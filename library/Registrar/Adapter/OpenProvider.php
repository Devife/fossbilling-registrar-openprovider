<?php

/** phpcs:ignoreFile */

/**
 * @copyright Devife (https://www.devife.com)
 * @license   MIT
 *
 * This source file is subject to the MIT License that is bundled
 * with this source code in the file LICENSE
 * 
 * Support at support@devife.com
 */

require_once __DIR__ . "/OpenProvider/API.php";

class Registrar_Adapter_OpenProvider extends Registrar_AdapterAbstract
{
    private $config = array(
        'Username'   => null,
        'Password' => null,
        'ApiUrl' => null
    );

    private const MODULE_VERSION = "0.1";
    private const PATH_LOG = "./library/Registrar/Adapter";
    private const DIR_LOG = "logs";
    private const FILE_LOG = "openprovider.log";

    public function __construct($options)
    {
        if (isset($options['Username']) && !empty($options['Username'])) {
            $this->config['Username'] = $options['Username'];
            unset($options['Username']);
        } else {
            throw new Registrar_Exception('OpenProvider Registrar module error.<br>Please update configuration parameter "Reseller Username" at "Configuration -> Domain registration"');
        }

        if (isset($options['Password']) && !empty($options['Password'])) {
            $this->config['Password'] = $options['Password'];
            unset($options['Password']);
        } else {
            throw new Registrar_Exception('OpenProvider Registrar module error.<br>Please update configuration parameter "Reseller Password" at "Configuration -> Domain registration"');
        }

        if (isset($options['ApiUrl']) && !empty($options['ApiUrl'])) {
            $this->config['ApiUrl'] = $options['ApiUrl'];
            unset($options['ApiUrl']);
        } else {
            throw new Registrar_Exception('OpenProvider Registrar module error.<br>Please update configuration parameter "API url" at "Configuration -> Domain registration"');
        }
    }
    /**
     * Return array with configuration
     */

    public static function getConfig()
    {
        return array(
            'label'     =>  'OpenProvider registrar',
            'form'  => array(
                'Username' => array(
                    'text',
                    array(
                        'label' => 'Username',
                        'description' => '',
                        'required' => true,
                    ),
                ),
                'Password' => array(
                    'password',
                    array(
                        'label' => 'Password',
                        'description' => '',
                        'required' => true,
                    ),
                ),
                'ApiUrl' => array(
                    'text',
                    array(
                        'label' => 'Api url',
                        'description' => '',
                        'required' => true,
                    ),
                )
            ),
        );
    }

    public function getTlds(): array
    {
        return [];
    }

    public function registerDomain(Registrar_Domain $domain)
    { // Step 1: Ensure a customer handle exists
        $customerHandle = $this->_getOrCreateCustomer($domain->getContactRegistrar());

        // Step 2: Prepare the domain registration data
        $data = [
            'domain' => [
                'name' => $domain->getName(),
                'extension' => $domain->getTld(),
            ],
            'period' => $domain->getRegistrationPeriod(),
            'owner_handle' => $customerHandle,
            'admin_handle' => $customerHandle,
            'tech_handle' => $customerHandle,
            'ns_group' => 'dns-openprovider',
            'autorenew' => 'default'
        ];

        if (!empty($domain->getNs())) {
            $data['nameServers'] = $domain->getNs();
        }

        try {
            $response = $this->_request('POST', '/domains', $data);
            if ($response['code'] === 0) {
                return true;
            }
            throw new Registrar_Exception('Failed to register domain: ' . $response['msg']);
        } catch (Exception $e) {
            throw new Registrar_Exception('OpenProvider API Error: ' . $e->getMessage());
        }
    }

    public function isDomainAvailable(Registrar_Domain $domain)
    {
        $data = [
            'domains' => [
                [
                    'name' => $domain->getName(),
                    'extension' => $domain->getTld(),
                ],
            ],
        ];

        try {
            $response = $this->_request('POST', '/domains/check', $data);
            if (!empty($response['results']) && $response['results'][0]['status'] === 'free') {
                return true;
            }
            return false;
        } catch (Exception $e) {
            throw new Registrar_Exception('OpenProvider API Error: ' . $e->getMessage());
        }
    }

    public function isDomainCanBeTransferred(Registrar_Domain $domain)
    {
        $data = [
            'domains' => [
                [
                    'name' => $domain->getName(),
                    'extension' => $domain->getTld(),
                ],
            ],
        ];

        $response = $this->_request('POST', '/domains/check', $data);
        $result = $response['results'][0] ?? [];
        return isset($result['status']) && $result['status'] === 'transfer';
    }

    public function transferDomain(Registrar_Domain $domain)
    {
        // Step 1: Ensure a customer handle exists
        $customerHandle = $this->_getOrCreateCustomer($domain->getContactRegistrar());

        // Step 2: Prepare the domain transfer data
        $data = [
            'domain' => [
                'name' => $domain->getName(),
                'extension' => $domain->getTld(),
            ],
            'period' => $domain->getRegistrationPeriod(),
            'owner_handle' => $customerHandle,
            'admin_handle' => $customerHandle,
            'tech_handle' => $customerHandle,
            'ns_group' => 'dns-openprovider',
            'autorenew' => 'default',
            'auth_code' => $domain->getEpp(),
        ];

        $this->_request('POST', '/domains/transfer', $data);
    }

    public function renewDomain(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'domain' => [
                'name' => $domain->getName(),
                'extension' => $domain->getTld(),
            ],
            'period' => $domain->getRenewalPeriod(),
        ];

        $this->_request('POST', "/domains/{$domainId}/renew", $data);
    }

    public function deleteDomain(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'skip_soft_quarantine' => false,
            'force_delete' => false,
            'type' => 'By user'
        ];

        $this->_request('DELETE', "/domains/{$domainId}", $data);
    }

    public function getEpp(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $response = $this->_request('GET', "/domains/{$domainId}/authcode");
        return $response['authCode'] ?? null;
    }

    public function getDomainDetails(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $response = $this->_request('GET', "/domains/{$domainId}");
        $opDomain = $response['data'];

        $domain->setRegistrationTime((string) $opDomain['creation_date']);
        $domain->setExpirationTime((string) $opDomain['expiration_date']);
        $domain->setPrivacyEnabled($opDomain['is_private_whois_enabled']);

        // create new Domain obj to return
        $newDomain = new Registrar_Domain();

        // set SLD and TLD
        $newDomain->setSld($domain->getSld());
        $newDomain->setTld($domain->getTld());
        $newDomain->setRegistrationTime((string) $opDomain['creation_date']);
        $newDomain->setExpirationTime((string) $opDomain['expiration_date']);
        $newDomain->setPrivacyEnabled($opDomain['is_private_whois_enabled']);

        $registrarContact = new Registrar_Domain_Contact();
        $adminContact = new Registrar_Domain_Contact();
        $techContact = new Registrar_Domain_Contact();

        // Set contact data on our Domain obj using info from our API call
        foreach (['Registrant', 'Admin', 'Tech'] as $contactType) {
            $owner = $opDomain->owner;
            $contact = $registrarContact;

            if ($contactType == 'Admin') {
                $contact = $adminContact;
            }
            if ($contactType == 'Tech') {
                $contact = $techContact;
            }

            $split = explode(" ", $owner->full_name);
            $lastName = end($split);
            $firstName = str_replace($lastName, '', $owner->full_name);

            $contact->setFirstName((string) $firstName);
            $contact->setLastName((string) $lastName);
            // $contact->setEmail((string) $contactApi->EmailAddress);
            // $contact->setTel((string) $contactApi->Phone);
            // $contact->setAddress1((string) $contactApi->Address1);
            // $contact->setAddress2((string) $contactApi->Address2);
            // $contact->setCity((string) $contactApi->City);
            // $contact->setState((string) $contactApi->StateProvince);
            // $contact->setCountry((string) $contactApi->Country);
            // $contact->setZip((string) $contactApi->PostalCode);
        }

        $newDomain->setContactRegistrar($registrarContact);
        $newDomain->setContactAdmin($adminContact);
        $newDomain->setContactTech($techContact);

        return $newDomain;
    }

    public function modifyNs(Registrar_Domain $domain)
    {
        // Step 1: Fetch the OpenProvider domain ID
        $domainId = $this->_getDomainId($domain);

        $ns = [];
        $ns[] = ["name" => $domain->getNs1()];
        $ns[] = ["name" => $domain->getNs2()];
        if ($domain->getNs3()) {
            $ns[] = ["name" => $domain->getNs3()];
        }
        if ($domain->getNs4()) {
            $ns[] = ["name" => $domain->getNs4()];
        }

        // Step 2: Prepare the request data
        $data = [
            'name_servers' => $ns,
        ];

        // Step 3: Send the PUT request to update nameservers
        $this->_request('PUT', "/domains/{$domainId}", $data);
    }

    public function modifyContact(Registrar_Domain $domain)
    {
        // Step 1: Fetch the OpenProvider domain ID
        $domainId = $this->_getDomainId($domain);

        // Step 2: Get or create the customer handle
        $customerHandle = $this->_getOrCreateCustomer($domain->getContactAdmin());

        // Step 3: Prepare the request data
        $data = [
            'owner_handle' => $customerHandle,
            'admin_handle' => $customerHandle,
            'tech_handle' => $customerHandle,
        ];

        // Step 4: Send the PUT request to update contact
        $this->_request('PUT', "/domains/{$domainId}", $data);
    }

    public function lock(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'is_locked' => true,
        ];

        $this->_request('PUT', "/domains/{$domainId}", $data);
    }

    public function unlock(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'is_locked' => false,
        ];

        $this->_request('PUT', "/domains/{$domainId}", $data);
    }

    public function enablePrivacyProtection(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'is_private_whois_enabled' => true,
        ];


        $this->_request('PUT', "/domains/{$domainId}", $data);
    }

    public function disablePrivacyProtection(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'is_private_whois_enabled' => false,
        ];

        $this->_request('PUT', "/domains/{$domainId}", $data);
    }

    private function _getDomainId(Registrar_Domain $domain)
    {
        $data = [
            'full_name' => $domain->getName() . '.' . $domain->getTld(),
        ];

        try {
            $response = $this->_request('GET', '/domains', $data);
            if (!empty($response['results']) && count($response['results']) > 0) {
                return $response['results'][0]['id']; // Return the OpenProvider domain ID
            }
            throw new Registrar_Exception('Domain not found in OpenProvider: ' . $domain->getName());
        } catch (Exception $e) {
            throw new Registrar_Exception('Failed to fetch domain ID: ' . $e->getMessage());
        }
    }

    private function _getOrCreateCustomer(Registrar_Domain_Contact $contact)
    {
        // Step 1: Check if the customer already exists by email
        $existingCustomerHandle = $this->_findCustomerByEmail($contact->getEmail());
        if ($existingCustomerHandle) {
            return $existingCustomerHandle;
        }

        // Step 2: Create a new customer if not found
        $data = [
            'email' => $contact->getEmail(),
            'phone' => $contact->getTel(),
            'addressLine1' => $contact->getAddress1(),
            'city' => $contact->getCity(),
            'country' => $contact->getCountry(),
            'firstName' => $contact->getFirstName(),
            'lastName' => $contact->getLastName(),
            'zipCode' => $contact->getZip(),
            'companyName' => $contact->getCompany() ?? '',
        ];

        try {
            $response = $this->_request('POST', '/customers', $data);
            if (isset($response['handle'])) {
                return $response['handle'];
            }
            throw new Registrar_Exception('Failed to create customer: ' . $response['msg']);
        } catch (Exception $e) {
            throw new Registrar_Exception('OpenProvider API Error: ' . $e->getMessage());
        }
    }

    private function _findCustomerByEmail($email)
    {
        $data = [
            'email_pattern' => $email, // Search by email pattern
        ];

        try {
            $response = $this->_request('GET', '/customers', $data);
            if (!empty($response['results']) && count($response['results']) > 0) {
                return $response['results'][0]['handle']; // Return the customer handle
            }
            return null; // No matching customer found
        } catch (Exception $e) {
            throw new Registrar_Exception('Failed to find customer by email: ' . $e->getMessage());
        }
    }

    /**
     * Send OpenProvider request
     */
    private function _request($method, $url, $data = []): array
    {
        try {
            $username   = $this->config['Username'];
            $password   = $this->config['Password'];
            $apiUrl     = $this->config['ApiUrl'];

            $op     = new OpenProvider_API();
            $op->setApi_login($username, $password, $apiUrl);

            $response = $op->request($method, $url, $data);
            var_dump("request", $response);
            $this->_logResponse($method, $url, $data, $response);
            return $response;
        } catch (Exception $e) {
            $this->_logError($method, $url, $data, $e->getMessage());
            throw $e;
        }
    }

    private function _logResponse($method, $url, $data, $response)
    {
        file_put_contents(self::PATH_LOG . '/' . self::DIR_LOG . '/' . self::FILE_LOG, json_encode([
            'method' => $method,
            'url' => $url,
            'data' => $data,
            'response' => $response,
        ], JSON_PRETTY_PRINT), FILE_APPEND);
    }

    private function _logError($method, $url, $data, $error)
    {
        file_put_contents(self::PATH_LOG . '/' . self::DIR_LOG . '/' . self::FILE_LOG, json_encode([
            'method' => $method,
            'url' => $url,
            'data' => $data,
            'error' => $error,
        ], JSON_PRETTY_PRINT), FILE_APPEND);
    }
}
