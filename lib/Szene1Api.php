<?php
/**
 * A simple, modern and CURL-free Library for PHP 5.3+
 * to access the Szene1 {@link http://szene1.at) XML REST API
 *
 * Documentation for the API is available at 
 * {@link http://wiki.szene1.co.at/wiki/Weblife1_API}
 *
 * @package   Szene1Api
 * @author    Christoph Hochstrasser <christoph.hochstrasser@gmail.com>
 * @license   MIT License
 */
namespace Szene1;

use StdClass,
    SimpleXMLElement,
    InvalidArgumentException;

class Exception extends \Exception
{}

class Api
{
    /** @var string Base URL for all API Requests */
    protected $baseUrl = "http://rest.api.weblife1.com";
    
    /** @var string API Key identifying the Application */
    protected $apiKey;
    
    /** @var string */
    protected $apiSecret;
    
    /** @var StdClass */
    protected $session;
    
    /**
     * Some default options for the HTTP Stream wrapper
     * @var array
     */
    protected $defaultHttpOptions = array(
        "method" => "GET",
        "timeout" => 10
    );
    
    const ERROR_INVALID_AUTHTOKEN = 104;
    
    /**
     * Constructor
     *
     * Takes an array of options:
     *   - "api_key":    API Key obtained on registration
     *   - "api_secret": API Secret obtained on registration of the App
     *   - "base_url":   Base URL for all API requests, defaults to "http://rest.api.weblife1.com"
     *   - "session":    Set the API session, e.g. to restore it from a PHP Session
     *
     * @param array $options Array of options
     */
    function __construct(Array $options = array())
    {
        $this->setOptions($options);
    }
    
    /**
     * @see api()
     */
    function __invoke($path, Array $params = array(), $method = "GET") 
    {
        return $this->api($path, $params, $method);
    }
    
    /**
     * Shortcut for HTTP GET Requests
     *
     * @param  string $path
     * @param  array  $params
     * @return SimpleXMLElement
     */
    function get($path, Array $params = array())
    {
        return $this->api($path, $params, "GET");
    }
    
    /**
     * Shortcut for HTTP POST Requests
     *
     * @param  string $path
     * @param  array  $params
     * @return SimpleXMLElement
     */
    function post($path, Array $params = array())
    {
        return $this->api($path, $params, "POST");
    }
    
    /**
     * Overrides options
     *
     * @param  array $options
     * @return Api
     */
    function setOptions(Array $options)
    {
        if (!$options) {
            return $this;
        }
        foreach ($options as $key => $value) {
            // Convert under_scored to CamelCase
            $key = str_replace(" ", null, ucwords(str_replace("_", " ", strtolower($key))));
            $setter = "set" . $key;
            
            if (is_callable(array($this, $setter))) {
                $this->{$setter}($value);
            }
        }
        return $this;
    }
    
    /**
     * Sends a login request and stores the returned session information
     * to enable user-based requests
     *
     * The session info (user ID, user name, authtoken) is available via the 
     * {@see getSession()}. Persisting this object is up to the Developer!
     *
     * @throws InvalidArgumentException|Szene1\Exception
     * @param  string $username
     * @param  string $password
     * @return Api
     */
    function login($username, $password)
    {
        if ($this->session) {
            throw new Exception("Session already exists. Please logout the current"
                . " user before you start a new login attempt.");
        }
        
        if (empty($username) or empty($password)) {
            throw new InvalidArgumentException("No valid username or password given");
        }
        
        $response = $this->api("user/login", array(
            "username" => $username,
            "password" => md5($password)
        ));
        
        $session = new StdClass;
        foreach ($response as $key => $value) {
            $session->{$key} = current($value);
        }
        $this->session = $session;
        
        return $this;
    }
    
    /**
     * Log out the current user
     *
     * Note: It takes the Auth Token from the session property, so make sure a
     * valid session object is set.
     *
     * @throws Exception if No session is set
     */
    function logout()
    {
        if (!$this->session) {
            throw new Exception("No valid session.");
        }
        
        $authtoken = $this->session->authtoken;
        
        try {
            $response  = $this->api("user/logout", array(
                "authtoken" => $authtoken
            ));
        } catch (Exception $e) {}
        
        $this->session = null;
        return $this;
    }
    
    /**
     * Does requests to a section/method
     *
     * @param  string|array $path Either as section/method string (e.g. "system/version")
     *                            or callback style, e.g. array("system", "version")
     * @param  array  $params
     * @param  string $method
     * @return SimpleXMLElement
     */
    protected function api($path, Array $params = array(), $httpMethod = "GET")
    {
        $httpMethod = strtoupper($httpMethod);
        
        if (is_string($path) and (false !== strpos($path, "/"))) {
            $path = trim($path, "/");
            list($section, $method) = explode("/", $path);
            
        } else if (is_array($path)) {
            list($section, $method) = $path;
            
        } else {
            throw new Exception("Not a valid format for an API Method");
        }
        
        $authSecret = $this->getAuthSecret($section, $method);
        $url        = $this->baseUrl . "/" . $section . "/" . $method;
        
        $params["apikey"]     = $this->apiKey;
        $params["authsecret"] = $authSecret;
        
        if ($session = $this->getSession()) {
            $params["authtoken"] = $session->authtoken;
        }
        
        if ("GET" == $httpMethod) {
            foreach ($params as $param => $value) {
                $url .= "/" . $param . "/" . urlencode($value);
            }
            $params = array();
        }
        
        $content = $this->request($url, $params, $httpMethod);
        
        try {
            $xml = new SimpleXMLElement($content);
            
        // XML Parse error
        } catch (\Exception $e) {
            throw new Exception(
                "String \"$content\" could not be parsed as XML"
            );
        }
        
        // API Error
        if (isset($xml->errorcode)) {
            // Error: Invalid Auth token, session is not valid anymore
            if (static::ERROR_INVALID_AUTHTOKEN == $xml->errorcode) {
                $this->session = null;
            }
            throw new Exception(
                "API Error {$xml->errorcode}: {$xml->errormessage}", 
                (int) $xml->errorcode
            );
        }
        return $xml;
    }
    
    /**
     * Sets the base url for all requests
     *
     * @param  string $url
     * @return Api
     */
    function setBaseUrl($url)
    {
        $this->baseUrl = $url;
        return $this;
    }
    
    function setApiKey($key)
    {
        $this->apiKey = $key;
        return $this;
    }
    
    function setApiSecret($secret)
    {
        $this->apiSecret = $secret;
        return $this;
    }
    
    function setSession($session)
    {
        if (!is_object($session)) {
            throw new InvalidArgumentException("Session must be given as object");
        }
        $this->session = $session;
        return $this;
    }
    
    function getSession()
    {
        return $this->session;
    }
    
    /**
     * Sends a HTTP request via the HTTP fopen wrapper and returns the response body
     * 
     * @throws Szene1\Exception on Error
     * @param  string $url
     * @param  array  $parameters
     * @param  string $method
     * @return string The response body
     */
    protected function request($url, Array $parameters = array(), $method = "GET")
    {
        $method = strtoupper($method);
        
        $opts = array(
            "method" => $method
        );
        
        if ($parameters) {
            $query = http_build_query($parameters);
            if ("GET" == $method) {
                if (false === strpos($url, "?")) {
                    $url .= "?";
                } else {
                    $url .= "&";
                }
                $url .= $query;
            } else {
                $opts["content"] = $query;
            }
        }
        $opts     = array("http" => array_merge($this->defaultHttpOptions, $opts));
        $context  = stream_context_create($opts);
        $contents = file_get_contents($url, false, $context);
        
        // Read HTTP status line
        $status = $http_response_header[0];
        list($ver, $code, $msg) = sscanf($status, "%s %d %[^$]s");
        
        if ($code >= 400 and $code <= 599) {
            throw new Exception("Error {$code}: {$msg}", $code);
        }
        return $contents;
    }
    
    /**
     * Hashes the section, method, API Key and API Secret with MD5 for 
     * usage as Auth Secret
     *
     * @param  string $section
     * @param  string $method
     * @return string
     */
    protected function getAuthSecret($section, $method)
    {
        return md5($section . $method . $this->apiKey . $this->apiSecret);
    }
}
