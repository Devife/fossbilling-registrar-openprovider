<?php

/** phpcs:ignoreFile */


/**
 * Class OpenProvider_API
 */
class OpenProvider_API
{
    public $loginusername = null;
    public $loginpassword = null;
    public $loginapiurl = null;
    public $endpoint = null;
    public $output = null;
    public $loginAuthToken = null;

    public $debug = false;


    /**
     * Set API login credentials
     *
     * @param $username
     * @param $password
     * @param $apiUrl
     */
    function setApi_Login($username, $password, $apiUrl)
    {
        $this->loginusername = $username;
        $this->loginpassword = $password;
        $this->loginapiurl = rtrim($apiUrl, '/');
        $this->endpoint = $this->loginapiurl . '/v1beta';
    }

    /**
     * Set API to debug mode
     */
    function setApi_debug()
    {
        $this->debug = true;
    }

    /**
     * Set the API output result
     *
     * @param $outputresult
     */
    function setApi_output($outputresult)
    {
        $this->output = $outputresult;
    }

    /**
     * Request an API token
     *
     * @return mixed
     */
    function requestAccessToken()
    {
        if (!isset($this->loginAuthToken) || empty($this->loginAuthToken)) {
            $data = array(
                'username' => $this->loginusername,
                'password' => $this->loginpassword,
            );

            $url        = $this->loginapiurl . '/auth/token';

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type' => 'application/json',
                'charset' => 'utf-8'
            ]);

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, "OpenProvider api agent at " . gethostname());

            $response     = curl_exec($ch);

            curl_close($ch);

            $responseData = json_decode((string)$response, true);

            if (!empty($responseData)) {
                $this->loginAuthToken = $responseData['data']['token'];
            }
        }

        return $this->loginAuthToken;
    }

    /**
     * Do API request
     *
     * @param $requesttype
     * @param $request
     * @param array $data
     * @param string $version
     * @return array|mixed|string
     */
    function request($requesttype, $request, $data = array())
    {
        $data = [
            'body'          => $data
        ];

        $accessToken = $this->requestAccessToken();

        if (empty($accessToken)) {
            $error = array();
            $error['error']['message']  = 'Request failed';

            return json_encode($error);
        }

        $url        = $this->endpoint . $request;

        $ch         = curl_init();

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
        ]);

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requesttype);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "OpenProvider api agent at " . gethostname());

        $result     = curl_exec($ch);
        $httpcode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $debugdata      = array(
            'requesttype'   => $requesttype,
            'url'           => $url,
            'postdata'      => $data,
            'result'        => $result,
            'httpcode'      => $httpcode
        );

        if ($this->debug) {
            var_dump($debugdata);
        }

        $result = json_decode($result, 1);
        return $result;
    }
}
