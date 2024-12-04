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
        $customerHandle = $this->_getOrCreateCustomer($domain->getContactAdmin());

        // Step 2: Prepare the domain registration data
        $data = [
            'domain' => [
                'name' => $domain->getSld(),
                'extension' => $this->_stripTld($domain),
            ],
            'period' => $domain->getRegistrationPeriod(),
            'owner_handle' => $customerHandle,
            'admin_handle' => $customerHandle,
            'tech_handle' => $customerHandle,
            'ns_group' => 'dns-openprovider',
            'autorenew' => 'default'
        ];

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
                    'name' => $domain->getSld(),
                    'extension' => $this->_stripTld($domain),
                ],
            ],
        ];

        try {
            $response = $this->_request('POST', '/domains/check', $data);
            if (!empty($response['data']['results']) && $response['data']['results'][0]['status'] === 'free') {
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
                    'name' => $domain->getSld(),
                    'extension' => $this->_stripTld($domain),
                ],
            ],
        ];

        $response = $this->_request('POST', '/domains/check', $data);
        $result = $response['data']['results'][0] ?? [];
        return isset($result['data']['status']) && $result['data']['status'] === 'transfer';
    }

    public function transferDomain(Registrar_Domain $domain)
    {
        // Step 1: Ensure a customer handle exists
        $customerHandle = $this->_getOrCreateCustomer($domain->getContactAdmin());

        // Step 2: Prepare the domain transfer data
        $data = [
            'domain' => [
                'name' => $domain->getSld(),
                'extension' => $this->_stripTld($domain),
            ],
            'period' => $domain->getRegistrationPeriod(),
            'owner_handle' => $customerHandle,
            'admin_handle' => $customerHandle,
            'tech_handle' => $customerHandle,
            'ns_group' => 'dns-openprovider',
            'autorenew' => 'default',
            'auth_code' => $domain->getEpp(),
        ];

        $response = $this->_request('POST', '/domains/transfer', $data);
        if ($response['code'] === 0) {
            return true;
        }

        return false;
    }

    public function renewDomain(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'domain' => [
                'name' => $domain->getSld(),
                'extension' => $this->_stripTld($domain),
            ],
            'period' => $domain->getRegistrationPeriod(),
        ];

        $response = $this->_request('POST', "/domains/{$domainId}/renew", $data);
        if ($response['code'] === 0) {
            return true;
        }

        return false;
    }

    public function deleteDomain(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'skip_soft_quarantine' => false,
            'force_delete' => false,
            'type' => 'By user'
        ];

        $response = $this->_request('DELETE', "/domains/{$domainId}", $data);
        if ($response['code'] === 0) {
            return true;
        }

        return false;
    }

    public function getEpp(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $response = $this->_request('GET', "/domains/{$domainId}/authcode");
        return $response['data']['auth_code'] ?? null;
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
        $response = $this->_request('PUT', "/domains/{$domainId}", $data);
        if ($response['code'] === 0) {
            return true;
        }

        return false;
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
        $response = $this->_request('PUT', "/domains/{$domainId}", $data);
        if ($response['code'] === 0) {
            return true;
        }

        return false;
    }

    public function lock(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'is_locked' => true,
        ];

        $response = $this->_request('PUT', "/domains/{$domainId}", $data);
        if ($response['code'] === 0) {
            return true;
        }

        return false;
    }

    public function unlock(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'is_locked' => false,
        ];

        $response = $this->_request('PUT', "/domains/{$domainId}", $data);
        if ($response['code'] === 0) {
            return true;
        }

        return false;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'is_private_whois_enabled' => true,
        ];


        $response = $this->_request('PUT', "/domains/{$domainId}", $data);
        if ($response['code'] === 0) {
            return true;
        }

        return false;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain)
    {
        $domainId = $this->_getDomainId($domain);

        $data = [
            'is_private_whois_enabled' => false,
        ];

        $response = $this->_request('PUT', "/domains/{$domainId}", $data);
        if ($response['code'] === 0) {
            return true;
        }

        return false;
    }

    private function _stripTld(Registrar_Domain $domain)
    {
        $tld = $domain->getTld();
        return preg_replace("/^\.+|\.+$/", "", $tld);
    }

    private function _getDomainId(Registrar_Domain $domain)
    {
        $data = [
            'full_name' => $domain->getName(),
        ];

        try {
            $response = $this->_request('GET', '/domains', $data);
            if (!empty($response['data']['results']) && count($response['data']['results']) > 0) {
                return $response['data']['results'][0]['id']; // Return the OpenProvider domain ID
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
            'phone' => [
                'country_code' => $contact->getTelCc(),
                'area_code' => "6",
                'subscriber_number' => $contact->getTel()
            ],
            'company_name' => $contact->getCompany() ?? '',
            'address' => [
                'street' => $contact->getAddress1(),
                'zipcode' => $contact->getZip(),
                'city' => $contact->getCity(),
                'state' => $contact->getState(),
                'country' => $contact->getCountry(),
            ],
            'name' => [
                'first_name' => $contact->getFirstName(),
                'last_name' => $contact->getLastName(),
            ]
        ];

        try {
            $response = $this->_request('POST', '/customers', $data);
            if (isset($response['data']['handle'])) {
                return $response['data']['handle'];
            }
            // var_dump(json_encode($data), $response);
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
            if (!empty($response['data']['results']) && count($response['data']['results']) > 0) {
                return $response['data']['results'][0]['handle']; // Return the customer handle
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
