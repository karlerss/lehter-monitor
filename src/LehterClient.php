<?php
namespace Lehter\Monitor;


use InvalidArgumentException;
use Raven_Client;
use Raven_Compat;
use Raven_Context;
use Raven_CurlHandler;
use Raven_Util;

class LehterClient extends Raven_Client
{


    /**
     * LehterClient constructor.
     */
    public function __construct($options_or_dsn=null, $options=array())
    {
        if (is_null($options_or_dsn) && !empty($_SERVER['SENTRY_DSN'])) {
            // Read from environment
            $options_or_dsn = $_SERVER['SENTRY_DSN'];
        }
        if (!is_array($options_or_dsn)) {
            if (!empty($options_or_dsn)) {
                // Must be a valid DSN
                $options_or_dsn = LehterClient::parseDSN($options_or_dsn);
            } else {
                $options_or_dsn = array();
            }
        }
        $options = array_merge($options_or_dsn, $options);

        $this->logger = Raven_Util::get($options, 'logger', 'php');
        $this->server = Raven_Util::get($options, 'server');
        $this->secret_key = Raven_Util::get($options, 'secret_key');
        $this->public_key = Raven_Util::get($options, 'public_key');
        $this->project = Raven_Util::get($options, 'project', 1);
        $this->auto_log_stacks = (bool) Raven_Util::get($options, 'auto_log_stacks', false);
        $this->name = Raven_Util::get($options, 'name', Raven_Compat::gethostname());
        $this->site = Raven_Util::get($options, 'site', $this->_server_variable('SERVER_NAME'));
        $this->tags = Raven_Util::get($options, 'tags', array());
        $this->release = Raven_util::get($options, 'release', null);
        $this->trace = (bool) Raven_Util::get($options, 'trace', true);
        $this->timeout = Raven_Util::get($options, 'timeout', 2);
        $this->message_limit = Raven_Util::get($options, 'message_limit', self::MESSAGE_LIMIT);
        $this->exclude = Raven_Util::get($options, 'exclude', array());
        $this->severity_map = null;
        $this->shift_vars = (bool) Raven_Util::get($options, 'shift_vars', true);
        $this->http_proxy = Raven_Util::get($options, 'http_proxy');
        $this->extra_data = Raven_Util::get($options, 'extra', array());
        $this->send_callback = Raven_Util::get($options, 'send_callback', null);
        $this->curl_method = Raven_Util::get($options, 'curl_method', 'sync');
        $this->curl_path = Raven_Util::get($options, 'curl_path', 'curl');
        $this->curl_ipv4 = Raven_util::get($options, 'curl_ipv4', true);
        $this->ca_cert = Raven_util::get($options, 'ca_cert', $this->get_default_ca_cert());
        $this->verify_ssl = Raven_util::get($options, 'verify_ssl', true);
        $this->curl_ssl_version = Raven_Util::get($options, 'curl_ssl_version');

        $this->processors = $this->setProcessorsFromOptions($options);

        $this->_lasterror = null;
        $this->_user = null;
        $this->context = new Raven_Context();

        if ($this->curl_method == 'async') {
            $this->_curl_handler = new Raven_CurlHandler($this->get_curl_options());
        }
    }

    private function _server_variable($key)
    {
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        return '';
    }

    public static function parseDSN($dsn)
    {
        $url = parse_url($dsn);
        $scheme = (isset($url['scheme']) ? $url['scheme'] : '');
        if (!in_array($scheme, array('http', 'https'))) {
            throw new InvalidArgumentException('Unsupported Lehter monitor scheme: ' . (!empty($scheme) ? $scheme : '<not set>'));
        }
        $netloc = (isset($url['host']) ? $url['host'] : null);
        $netloc.= (isset($url['port']) ? ':'.$url['port'] : null);
        $rawpath = (isset($url['path']) ? $url['path'] : null);
        if ($rawpath) {
            $pos = strrpos($rawpath, '/', 1);
            if ($pos !== false) {
                $path = substr($rawpath, 0, $pos);
                $project = substr($rawpath, $pos + 1);
            } else {
                $path = '';
                $project = substr($rawpath, 1);
            }
        } else {
            $project = null;
            $path = '';
        }
        $username = (isset($url['user']) ? $url['user'] : null);
        $password = (isset($url['pass']) ? $url['pass'] : null);
        if (empty($netloc) || empty($project) || empty($username)) {
            throw new InvalidArgumentException('Invalid Sentry DSN: ' . $dsn);
        }

        $result = array(
            'server'     => sprintf('%s://%s%s/%s/store', $scheme, $netloc, $path, $project),
            'project'    => $project,
            'public_key' => $username,
            'secret_key' => $password,
        );


        return $result;
    }

    public function send($data)
    {
        /*echo json_encode($data);
        dd();*/
        if (is_callable($this->send_callback) && !call_user_func($this->send_callback, $data)) {
            // if send_callback returns falsely, end native send
            return;
        }

        if (!$this->server) {
            return;
        }
        //dd($data);
        $message = Raven_Compat::json_encode($data);
        //dd($message);
        if (function_exists("gzcompress")) {
            $message = gzcompress($message);
        }
        $message = base64_encode($message); // PHP's builtin curl_* function are happy without this, but the exec method requires it

        $client_string = 'raven-php/' . self::VERSION;
        $timestamp = microtime(true);
        $headers = array(
            'User-Agent' => $client_string,
            'Content-Type' => 'application/octet-stream'
        );

        $this->send_remote($this->server, $message, $headers);
    }

    private function send_remote($url, $data, $headers=array())
    {
        $parts = parse_url($url);
        $parts['netloc'] = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : null);
        $this->send_http($url, $data, $headers);
    }

    private function send_http($url, $data, $headers=array())
    {
        if ($this->curl_method == 'async') {
            $this->_curl_handler->enqueue($url, $data, $headers);
        } elseif ($this->curl_method == 'exec') {
            $this->send_http_asynchronous_curl_exec($url, $data, $headers);
        } else {
            //dd($data);
            $this->send_http_synchronous($url, $data, $headers);
        }
    }

    private function send_http_asynchronous_curl_exec($url, $data, $headers)
    {
        // TODO(dcramer): support ca_cert
        $cmd = $this->curl_path.' -X POST ';
        foreach ($headers as $key => $value) {
            $cmd .= '-H \''. $key. ': '. $value. '\' ';
        }
        $cmd .= '-d \''. $data .'\' ';
        $cmd .= '\''. $url .'\' ';
        $cmd .= '-m 5 ';  // 5 second timeout for the whole process (connect + send)
        $cmd .= '> /dev/null 2>&1 &'; // ensure exec returns immediately while curl runs in the background
        dd($cmd);
        exec($cmd);

        return true; // The exec method is just fire and forget, so just assume it always works
    }

    private function send_http_synchronous($url, $data, $headers)
    {
        $new_headers = array();
        foreach ($headers as $key => $value) {
            array_push($new_headers, $key .': '. $value);
        }
        // XXX(dcramer): Prevent 100-continue response form server (Fixes GH-216)
        $new_headers[] = 'Expect:';

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $new_headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $options = $this->get_curl_options();
        $ca_cert = $options[CURLOPT_CAINFO];
        unset($options[CURLOPT_CAINFO]);
        curl_setopt_array($curl, $options);

        curl_exec($curl);

        $errno = curl_errno($curl);
        // CURLE_SSL_CACERT || CURLE_SSL_CACERT_BADFILE
        if ($errno == 60 || $errno == 77) {
            curl_setopt($curl, CURLOPT_CAINFO, $ca_cert);
            curl_exec($curl);
        }

        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $success = ($code == 200);
        if (!$success) {
            // It'd be nice just to raise an exception here, but it's not very PHP-like
            $this->_lasterror = curl_error($curl);
        } else {
            $this->_lasterror = null;
        }
        curl_close($curl);

        return $success;
    }





}