<?php
namespace Jasny\SSO;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Single sign-on broker.
 *
 * The broker lives on the website visited by the user. The broken doesn't have any user credentials stored. Instead it
 * will talk to the SSO server in name of the user, verifying credentials and getting user information.
 */
class Broker
{
    /**
     * Url of SSO server
     * @var string
     */
    protected $url;

    /**
     * My identifier, given by SSO provider.
     * @var string
     */
    public $broker;

    /**
     * My secret word, given by SSO provider.
     * @var string
     */
    protected $secret;

    /**
     * Session token of the client
     * @var string
     */
    public $token;

    /**
     * User info recieved from the server.
     * @var array
     */
    protected $userinfo;

    
    
    /**
     * Class constructor
     *
     * @param string $url    Url of SSO server
     * @param string $broker My identifier, given by SSO provider.
     * @param string $secret My secret word, given by SSO provider.
     */
    public function __construct($url, $broker, $secret)
    {
        if (!$url) throw new \InvalidArgumentException("SSO server URL not specified");
        if (!$broker) throw new \InvalidArgumentException("SSO broker id not specified");
        if (!$secret) throw new \InvalidArgumentException("SSO broker secret not specified");

        $this->url = $url;
        $this->broker = $broker;
        $this->secret = $secret;
        
        if (isset($_COOKIE[$this->getCookieName()])) $this->token = $_COOKIE[$this->getCookieName()];
    }
    
    /**
     * Get the cookie name.
     * 
     * Note: Using the broker name in the cookie name.
     * This resolves issues when multiple brokers are on the same domain.
     * 
     * @return string
     */
    protected function getCookieName()
    {
        return 'sso_token_' . strtolower($this->broker);
    }

    /**
     * Generate session id from session key
     *
     * @return string
     */
    protected function getSessionId()
    {
        if (!$this->token) return null;

        $checksum = hash('sha256', 'session' . $this->token . $_SERVER['REMOTE_ADDR'] . $this->secret);
        return "SSO-{$this->broker}-{$this->token}-$checksum";
    }

    /**
     * Generate session token
     */
    public function generateToken()
    {
        if (isset($this->token)) return;
        
        $this->token = base_convert(md5(uniqid(rand(), true)), 16, 36);
        setcookie($this->getCookieName(), $this->token, time() + 3600);
    }

    /**
     * Check if we have an SSO token.
     *
     * @return boolean
     */
    public function isAttached()
    {
        return isset($this->token);
    }

    /**
     * Get URL to attach session at SSO server.
     *
     * @param array $params
     * @return string
     */
    public function getAttachUrl($params = [])
    {
        $this->generateToken();

        $data = [
            'command' => 'attach',
            'broker' => $this->broker,
            'token' => $this->token,
            'checksum' => hash('sha256', 'attach' . $this->token . $_SERVER['REMOTE_ADDR'] . $this->secret)
        ];
        
        return $this->url . "?" . http_build_query($data + $params);
    }

    /**
     * Attach our session to the user's session on the SSO server.
     *
     * @param string|true $returnUrl  The URL the client should be returned to after attaching
     */
    public function attach($returnUrl = null)
    {
        if ($this->isAttached()) return;

        if ($returnUrl === true) {
            $protocol = !empty($_SERVER['HTTPS']) ? 'https://' : 'http://';
            $returnUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }
        
        $params = ['return_url' => $returnUrl];
        $url = $this->getAttachUrl($params);

        header("Location: $url", true, 307);
        echo "You're redirected to <a href='$url'>$url</a>";
        exit();
    }

    /**
     * Get the request url for a command
     *
     * @param string $command
     * @param array  $params   Query parameters
     * @return string
     */
    protected function getRequestUrl($command, $params = [])
    {
        $params['command'] = $command;
        $params['sso_session'] = $this->getSessionId();
        
        return $this->url . '?' . http_build_query($params);
    }

    /**
     * Execute on SSO server.
     *
     * @param string       $method  HTTP method: 'GET', 'POST', 'DELETE'
     * @param string       $command Command
     * @param array|string $data    Query or post parameters
     * @return array
     */
    protected function request($method, $command, $data = null)
    {
        $url = $this->getRequestUrl($command, !$data || $method === 'POST' ? [] : $data);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($method === 'POST') {
            $post = is_string($data) ? $data : http_build_query($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }

        $response = curl_exec($ch);
        if (curl_errno($ch) != 0) {
            throw new Exception("Server request failed: " . curl_error($ch), 500);
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        list($contentType) = explode(';', curl_getinfo($ch, CURLINFO_CONTENT_TYPE));

        if ($contentType != 'application/json') {
            $message = "Expected application/json response, got $contentType";
            error_log($message . "\n\n" . $response);
            throw new Exception($message, $httpCode);
        }

        $data = json_decode($response, true);
        if ($httpCode >= 400) throw new Exception($data['error'] ?: $response, $httpCode);

        return $data;
    }


    /**
     * Log the client in at the SSO server.
     *
     * Only brokers marked trused can collect and send the user's credentials. Other brokers should omit $username and
     * $password.
     *
     * @param  string $username
     * @param  string $password
     * @return object
     */
    public function login($username = null, $password = null)
    {
        if (!isset($username)) $username = $_POST['username'];
        if (!isset($password)) $password = $_POST['password'];

        $result = $this->request('POST', 'login', compact('username', 'password'));
        $this->userinfo = $result;
        
        return $result;
    }

    /**
     * Logout at sso server.
     */
    public function logout()
    {
        return $this->request('logout');
    }

    /**
     * Get user information.
     */
    public function getUserInfo()
    {
        if (!isset($this->userinfo)) {
            $this->userinfo = $this->request('GET', 'userInfo');
        }

        return $this->userinfo;
    }
    

    /**
     * Handle notifications send by the SSO server
     *
     * @param string $event
     * @param object $data
     */
    public function on($event, $data)
    {
        if (method_exists($this, "on{$event}")) $this->{"on{$event}"}($data);
    }

    /**
     * Handle a login notification
     */
    protected function onLogin($data)
    {
        $this->userinfo = $data;
    }

    /**
     * Handle a logout notification
     */
    protected function onLogout()
    {
        $this->userinfo = null;
    }

    /**
     * Handle a notification about a change in the userinfo
     */
    protected function onUserinfo($data)
    {
        $this->userinfo = $data;
    }
}
