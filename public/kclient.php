<?php /** @noinspection ALL */
/**
 * @noinspection PhpMissingParamTypeInspection
 * @noinspection PhpMissingFieldTypeInspection
 * @noinspection PhpMissingStrictTypesDeclarationInspection
 * @noinspection PhpMissingReturnTypeInspection
 * @noinspection PhpStrFunctionsInspection
 * @noinspection PhpIssetCanBeReplacedWithCoalesceInspection
 */

/**
 * Usage:
 *  require_once 'kclient.php';
 *  $client = new KClient('http://tds.com/', 'CAMPAIGN_TOKEN');
 *  $client->sendUtmLabels(); # send only utm labels
 *  $client->sendAllParams(); # send all params
 *  $client
 *      ->keyword('[KEYWORD]')
 *      ->execute(); # use executeAndBreak() to break the page execution if there is redirect or some output
 * @psalm-suppress MissingReturnType
 * @psalm-suppress MissingParamType
 * @psalm-suppress MissingPropertyType
 */

define('KCLIENT_VERSION', '4.0.0');
define('KCLIENT_API_VERSION', '4');

class KClient
{
    const SUB_ID = 'subid';
    const SUB_ID_OLD = 'sub_id';
    const LP_TOKEN = 'token';
    const API_VERSION = 'version';
    const STATE_SESSION_KEY = 'keitaro_state';
    const STATE_SESSION_EXPIRES_KEY = 'keitaro_state_expires';
    const DEFAULT_TTL = 1;
    const NOT_FOUND_STATUS = 404;
    const SELF_STATIC_SERVING = 'self_static_serving';
    const REQUEST_METHOD = 'REQUEST_METHOD';
    const REQUEST_URI = 'REQUEST_URI';
    const FORCE_REDIRECT_OFFER = 'force_redirect_offer';
    const KEYWORD = 'keyword';
    const LANGUAGE = 'language';
    const API_TOKEN = 'token';
    const LP = '_lp';
    const TOKEN = '_token';
    const REFERRER = 'referrer';
    const SE_REFERRER = 'se_referrer';
    const X_REQUESTED_WITH = 'x_requested_with';
    const INFO = 'info';
    const GET_PARAMS = 'get_params';
    const POST_PARAMS = 'post_params';
    const URI = 'uri';
    const HEADERS = 'headers';
    const SERVER = 'server';
    const METHOD = 'method';
    const DEFAULT_ERROR = '[KClient] Something is wrong. Use $client->debug() to see the debug output.';
    const GET = 'GET';
    const UA = 'KHttpClient';
    public $tokenVariations = array('_token', 'token');
    public $filterServingHeaders = array(
        'Date',
        'X-Powered-By',
        'Last-Modified'
    );
    public $filterRequestHeaders = array(
        'connection'
    );

    public $allowedServingHeaders = array(
        'cache-control',
        'user-agent',
        'accept-encoding',
        'accept-language',
        'if-none-match',
        'if-modified-since',
        'referer',
        'cookie',
        'x-requested-with',
        'x-requested-by',
        'x-requested-for',
        'x-real-ip',
    );

    public $allowedServerParams = array('HTTPS', 'REMOTE_ADDR');
    public $filterParams = array('token');


    /**
     * @var KHttpClientInterface
     */
    private $httpClient;

    /**
     * @var KDispatcherInterface
     */
    private $dispatcher;
    /**
     * @var bool
     */
    private $debug = false;
    private $apiUrl;
    private $params = array();
    private $logEntries = array();

    private $sessionTtl = self::DEFAULT_TTL;
    /**
     * @var KApiResult
     */
    private $latestResult;
    /**
     * @var bool
     */
    private $stateRestored;

    private $compressionEnabled = true;
    /**
     * @var bool
     */
    private $sessionsDisabled = false;

    public $subIdVariations = array('_subid', 'subid');

    public $allowServeList = array(
        'php',
        'jpg', 'jpeg', 'png', 'gif', 'ico', 'svg', 'tif', 'tiff', 'bmp', 'webp',
        'avif', 'js', 'css', 'html', 'htm', 'txt', 'csv', 'pdf', 'xml', 'json', 'zip', 'tar', 'gz', 'rar',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'mp3', 'mp4', 'wav', 'ogg', 'webm',
        'flv', 'mov'
    );

    public $allowedResponseHeaders = array(
        'content-type',
        'content-encoding',
        'expires',
        'cache-control',
        'pragma',
        'last-modified',
        'etag',
    );

    public $staticFileDenyResponseHeaders = array(
        'set-cookie',
    );

    public function __construct($apiUrl, $token)
    {
        $this->apiUrl = KHelper::validateApiUrl($apiUrl);
        $this->campaignToken($token);
        $this->apiVersion(KCLIENT_API_VERSION);
        $this->param(self::INFO, 1);
        $this->fillParamsFromEnv();
        $this->setHttpClient(new KHttpClient());
        $this->setDispatcher(new KDispatcher());
    }

    /**
     * @deprecated
     */
    public function fillParams()
    {
        $this->fillParamsFromEnv();
        return $this;
    }

    public function fillParamsFromEnv($envServer = null, $getParams = null, $postParams = null)
    {
        $envServer = is_null($envServer) ? $_SERVER : $envServer;
        $getParams = is_null($getParams) ? $_GET : $getParams;
        $postParams = is_null($postParams) ? $_POST : $postParams;

        $this->param(self::HEADERS, KHelper::getHeadersFromServerParams($envServer, $this->filterRequestHeaders));
        $this->param(self::SERVER, KHelper::filterServerParams($envServer, $this->allowedServerParams));
        $this->param(self::METHOD, isset($envServer[self::REQUEST_METHOD]) ? $envServer[self::REQUEST_METHOD] : self::GET);

        $this->param(self::GET_PARAMS, $getParams);
        $this->param(self::POST_PARAMS, $postParams);

        $requestedUrl = KHelper::buildRequestedUrl($envServer);
        if (!empty($requestedUrl)) {
            $this->param(self::URI, $requestedUrl);
        }
    }

    public function currentPageAsReferrer()
    {
        $this->params[KClient::HEADERS]['referer'] = $this->getCurrentPage();
        return $this;
    }

    public function setSessionTtl($ttl)
    {
        $this->sessionTtl = $ttl;
        return $this;
    }

    public function debug($state = true)
    {
        $this->debug = $state;
        return $this;
    }

    /**
     * @deprecated
     */
    public function seReferrer($seReferrer)
    {
        return $this;
    }

    /**
     * @deprecated
     */
    public function referrer($referrer)
    {
        $this->params[KClient::HEADERS]['referer'] = $referrer;
        return $this;
    }

    /**
     * @deprecated
     */
    public function xRequestedWith($xRequestedWith)
    {
        $this->params[KClient::HEADERS][self::X_REQUESTED_WITH] = $xRequestedWith;
        return $this;
    }

    public function setHttpClient(KHttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        return $this;
    }

    public function setDispatcher(KDispatcherInterface $executor)
    {
        $this->dispatcher = $executor;
    }

    // @deprecated
    public function token($token)
    {
        return $this->campaignToken($token);
    }

    public function campaignToken($campaignToken)
    {
        $this->params[self::API_TOKEN] = $campaignToken;
        return $this;
    }

    public function apiVersion($version)
    {
        $this->params[self::API_VERSION] = $version;
        return $this;
    }

    public function ua($ua)
    {
        $this->params[self::UA] = $ua;
        return $this;
    }

    public function language($language)
    {
        $this->params[self::LANGUAGE] = $language;
        return $this;
    }

    public function keyword($keyword)
    {
        $this->params[self::KEYWORD] = $keyword;
        return $this;
    }

    public function forceRedirectOffer()
    {
        $this->params[self::FORCE_REDIRECT_OFFER] = 1;
    }

    public function enableStaticServing()
    {
        $this->params[self::SELF_STATIC_SERVING] = 1;
    }

    /**
     * @deprecated
     */
    public function sendUtmLabels()
    {
    }


    public function getSubId()
    {
        $result = $this->performRequest();
        if (empty($result->info->subId)) {
            $this->log('No sub_id is defined');
            return 'no_subid';
        }
        $subId = $result->info->subId;
        return $subId;
    }

    /**
     * @throws KClientError
     */
    public function getToken()
    {
        $result = $this->performRequest();
        if (empty($result->info->token)) {
            $this->log('No landing token is defined');
            return 'no_token';
        }
        $subId = $result->info->token;
        return $subId;
    }

    /**
     * @deprecated
     */
    public function sendAllParams()
    {
        // @deprecated
    }

    public function restoreFromSession()
    {
        if ($this->isStateRestored() || $this->sessionsDisabled) {
            return;
        }
        $this->dispatcher->startSession();

        if (!empty($_SESSION[self::STATE_SESSION_KEY])) {
            if ($_SESSION[self::STATE_SESSION_EXPIRES_KEY] < time()) {
                unset($_SESSION[self::STATE_SESSION_KEY]);
                unset($_SESSION[self::STATE_SESSION_EXPIRES_KEY]);
                $this->log('State expired');
            } else {
                $this->latestResult = KApiResult::fromStdClass(json_decode($_SESSION[self::STATE_SESSION_KEY], false));
                if (isset($this->latestResult) && isset($this->latestResult->headers)) {
                    $this->latestResult->headers = array();
                }
                $this->stateRestored = true;
                $this->log('State restored');
            }
        }
    }

    public function disableSessions()
    {
        $this->sessionsDisabled = true;
    }

    public function restoreFromQuery()
    {
        $subId = null;
        foreach ($this->subIdVariations as $name) {
            if (isset($_GET[$name])) {
                $subId = $_GET[$name];
                break;
            }
            if (isset($_POST[$name])) {
                $subId = $_POST[$name];
                break;
            }
        }

        $token = null;
        foreach ($this->tokenVariations as $name) {
            if (isset($_GET[$name])) {
                $token = $_GET[$name];
                break;
            }
            if (isset($_POST[$name])) {
                $token = $_POST[$name];
                break;
            }
        }

        if (isset($subId)) {
            $this->stateRestored = true;
            if (empty($this->latestResult)) {
                $info = new KApiResultInfo(null, null, $subId, null, null, $token, null, null);
                $this->latestResult = new KApiResult(null, null, 200, array(), $this->sessionTtl, null, array(), $info, null);
            }

            $this->storeState($this->latestResult);
            $this->stateRestored = true;
        }
    }

    /**
     * @return bool
     */
    public function isStateRestored()
    {
        return $this->stateRestored;
    }

    public function param($name, $value)
    {
        if (in_array($name, $this->filterParams)) {
            return $this;
        }
        $this->params[$name] = $value;
        return $this;
    }

    public function params($value)
    {
        if (!empty($value)) {
            if (is_string($value)) {
                parse_str($value, $result);
                foreach ($result as $name => $value) {
                    $this->param($name, $value);
                }
            }
        }

        return $this;
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->latestResult = null;
    }

    /**
     * @throws KClientError
     * @return KApiResult
     */
    public function performRequest()
    {
        if ($this->latestResult) {
            return $this->latestResult;
        }

        $request = $this->buildRequestUrl();
        $params = $this->getParams();

        $this->log('Request: ' . $request);
        $this->log('Params: ' . json_encode(KHelper::secureParams($params)));

        try {
            $options = array(
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => $this->generateUserAgent(),
                CURLOPT_HEADER => [
                    'Host:' . $this->getCurrentPage()
                ],
            );

            if ($this->compressionEnabled) {
                $options[CURLOPT_ENCODING] = 'gzip';
            }

            $response = $this->httpClient->request(KHttpClient::POST, $request, $params, $options);
            $this->log('Response: status: ' . $response->status . ', size ' . mb_strlen($response->body) . ' bytes');
        } catch (KClientError $e) {
            if ($this->debug) {
                throw $e;
            } else {
                $errorCode = $e->getHumanCode();
                $errorCode = $errorCode ? $errorCode . ' ' : '';
                $this->dispatcher->sendBody($errorCode . self::DEFAULT_ERROR);
                return null;
            }
        }


        $this->latestResult = KApiResult::fromStdClass(json_decode($response->body));

        return $this->latestResult;
    }

    /**
     * @param bool $break
     * @param bool $print
     * @return bool|string
     * @throws KClientError
     */
    public function execute($break = false, $print = true)
    {
        $result = $this->performRequest();
        $this->storeState($result);

        $body = KHelper::buildBody($result->error, $result->body);

        if (!$print) {
            return $body;
        }

        if (isset($result->cookies)) {
            $this->setAllCookies($this->latestResult->cookies);
        }
        $this->sendAllHeaders($result);
        $this->dispatcher->sendHttpStatus($result->status);
        $this->dispatcher->sendBody($body);
    }

    /**
     * @throws KClientError
     */
    public function executeAndBreak()
    {
        if (!empty($this->params[self::SELF_STATIC_SERVING])) {
            $this->tryServe($_SERVER[self::REQUEST_METHOD], $_SERVER[self::REQUEST_URI]);
        }

        $result = $this->performRequest();
        $this->storeState($result);
        $body = KHelper::buildBody(
            isset($result->error) ? $result->error : null,
            isset($result->body) ? $result->body : null,
            isset($result->contentType) ? $result->contentType : null
        );
        $this->sendAllHeaders($result);

        if (isset($result->cookies)) {
            $this->setAllCookies($this->latestResult->cookies, $this->latestResult->ttl);
        }

        if ($this->debug) {
            $this->showLog();
        }

        if (!empty($body)) {
            $this->dispatcher->sendBody($body);
            die();
        }

        if (!empty($result->headers) && KHelper::containsAction($result->headers)) {
            $this->dispatcher->sendBody($body);
            die();
        }

        if (!empty($result->status) && $result->status == self::NOT_FOUND_STATUS) {
            $this->dispatcher->sendBody($body);
            die();
        }
    }

    public function getContent()
    {
        $result = $this->performRequest();
        return KHelper::buildBody($result->error, $result->body, $result->contentType);
    }

    public function showLog($separator = '<br />')
    {
        echo '<hr>' . implode($separator, $this->logEntries) . '<hr>';
    }

    public function log($msg)
    {
        if ($this->debug) {
            error_log($msg);
        }
        $this->logEntries[] = $msg;
    }

    public function getLog()
    {
        return $this->logEntries;
    }

    public function getParams()
    {
        return $this->params;
    }

    /**
     * @param KApiResult $result
     * @return void
     */
    private function sendAllHeaders($result)
    {
        $this->checkHeadersSent();
        if (isset($result->headers)) {
            $this->dispatcher->sendHeaders($result->headers);
        }
        if (isset($result->status)) {
            $this->dispatcher->sendHttpStatus($result->status);
        }

        // if (isset($result->contentType)) {
        //   $this->dispatcher->sendContentType($result->contentType);
        //}
    }

    public function checkHeadersSent()
    {
        $file = '';
        $line = '';
        if (headers_sent($file, $line)) {
            $msg = "Headers already sent in ";
            if (!empty($file)) {
                $msg .= " {$file} line {$line})";
            }
            $this->dispatcher->sendBody($msg);
        }
    }

    /**
     * @param KApiResult $result
     * @param int $ttl
     * @return void
     */
    private function storeState($result)
    {
        $ttl = $this->latestResult->ttl;
        if ($this->sessionsDisabled) {
            return;
        }

        $this->dispatcher->setSessionValue(self::STATE_SESSION_KEY, json_encode($result->asArray()));
        $this->dispatcher->setSessionValue(self::STATE_SESSION_EXPIRES_KEY, time() + $ttl);

        if (empty($result->info)) {
            return;
        }

        if (!empty($result->info->subId)) {
            $this->dispatcher->setSessionValue(self::SUB_ID_OLD, $result->info->subId);
            $this->dispatcher->setSessionValue(self::SUB_ID, $result->info->subId);
        }
        if (!empty($result->info->token)) {
            $this->dispatcher->setSessionValue(self::LP_TOKEN, $result->info->token);
        }
    }


    /**
     * @param array<KCookie> $cookies
     * @return void
     */
    private function setAllCookies($cookies)
    {
        $domain = KHelper::findCookieDomain();
        $secure = KHelper::isHttps();
        foreach ($cookies as $cookie) {
            $this->dispatcher->setCookie($cookie, $domain, $secure);
        }
    }

    public function getOffer($params = array(), $fallback = 'no_offer')
    {
        $result = $this->performRequest();
        $token = $this->getToken();
        if (empty($token)) {
            $this->log('Campaign hasn\'t returned offer');
            return $fallback;
        }
        $params[self::LP] = 1;
        $params[self::TOKEN] = $result->info->token;
        return $this->buildOfferUrl($params);
    }

    public function isBot()
    {
        $result = $this->performRequest();
        if (isset($result->info)) {
            return isset($result->info->bot) ? $result->info->bot : false;
        }
        return false;
    }

    public function isUnique($level = 'campaign')
    {
        $result = $this->performRequest();
        if (isset($result->info) && $result->info->uniqueness) {
            return isset($result->info->uniqueness->$level) ? $result->info->uniqueness->$level : false;
        }
        return false;
    }

    public function getBody()
    {
        $result = $this->performRequest();
        if (is_null($result)) {
            throw new KClientError('Empty response');
        }
        return $result->body;
    }

    public function getHeaders()
    {
        $result = $this->performRequest();
        return $result->headers;
    }

    private function startSession()
    {
        session_start();
    }

    private function buildOfferUrl($params = array())
    {
        $params = http_build_query($params);
        return "{$this->apiUrl}/?{$params}";
    }


    private function getCurrentPage()
    {
        return KHelper::buildRequestedUrl($_SERVER);
    }

    private function buildRequestUrl()
    {
        return $this->apiUrl . "/click_api/v" . KCLIENT_API_VERSION;
    }

    public function addToServe($extension)
    {
        $this->allowServeList[] = $extension;
    }

    public function removeFromServe($extension)
    {
        $key = array_search($extension, $this->allowServeList);
        if ($key !== false) {
            unset($this->allowServeList[$key]);
        }
    }

    /**
     * @throws KClientError
     */
    public function tryServe($method, $path)
    {
        ini_set('memory_limit', '-1');
        $backtrace = debug_backtrace();
        $includingFile = basename($backtrace[1]['file']);
        $requestUri = parse_url($path, PHP_URL_PATH);
        $requestedFile = basename($requestUri);
        if ($includingFile == $requestedFile) {
            return;
        }

        if (!KHelper::includeInAllowlist($requestedFile, $this->allowServeList)) {
            return;
        }

        $this->serve($method, $path);
        $this->dispatcher->stop();
    }

    /**
     * @param string $method
     * @param string $path
     * @throws KClientError
     */
    private function serve($method, $path)
    {
        KHelper::removeHeaders($this->filterServingHeaders);

        $externalUrl = $this->apiUrl . $path;

        $cookie = KHelper::filterCookies(isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : '');

        $headers = KHelper::getHeadersFromServerParams($_SERVER, $this->filterRequestHeaders);
        $headers = KHelper::filterHeaders($headers, $this->allowedServingHeaders);

        $opts = array(
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTPHEADER => KHelper::flattenHeaders($headers),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => $this->generateUserAgent(),
        );

        if (!empty($cookie)) {
            $opts[CURLOPT_COOKIE] = $cookie;
        }

        $params = array_merge($_GET, $_POST);

        try {
            $response = $this->httpClient->request($method, $externalUrl, $params, $opts);
        } catch (KClientError $e) {
            $this->log('Response error: ' . $e->getCode() . ': ' . $e->getMessage());
            $response = new KResponse($e->getCode(), array(), '');
        }

        $this->dispatcher->sendHttpStatus($response->status);
        $headers = KHelper::filterHeaders($response->headers, $this->allowedResponseHeaders);
        $this->dispatcher->sendHeaders($headers);

        if ($response->isGzipped()) {
            $body = $response->compressedBody();
        } else {
            $body = $response->body;
        }

        $this->dispatcher->sendBody($body);
    }

    public function generateUserAgent()
    {
        return self::UA . ' v' . KCLIENT_VERSION . ', PHP v' . phpversion();
    }
}

class KHelper
{
    const HEADER_COOKIE = 'cookie';

    /**
     * @param array<string> $headers
     * @return bool
     */
    public static function containsAction($headers)
    {
        if (empty($headers)) {
            return false;
        }
        foreach ($headers as $header) {
            if (strpos($header, 'location:') === 0) {
                return true;
            }
            if (strstr($header, '404 Not Found')) {
                return true;
            }
        }
        return false;
    }

    public static function buildBody($error, $body, $contentType = null)
    {
        $content = '';
        if (!empty($error)) {
            $content .= $error;
        }

        if (!empty($body)) {
            if (isset($contentType) && (strstr($contentType, 'image') || strstr($contentType, 'application/pdf'))) {
                $content = base64_decode($body);
            } else {
                $content .= $body;
            }
        }

        return $content;
    }

    /**
     * @param string $apiUrl
     * @return string
     */
    public static function validateApiUrl($apiUrl)
    {
        if (!empty($apiUrl)) {
            $request = parse_url($apiUrl);
            $url = "{$request['scheme']}://{$request['host']}";

            if (isset($request['port'])) {
                $url .= ':' . $request['port'];
            }
        }
        return $url;
    }

    /**
     * @param array $serverParams
     * @param array $denyList
     * @return array
     */
    public static function getHeadersFromServerParams($serverParams, $denyList)
    {
        $result = array();
        foreach ($serverParams as $name => $value) {
            if (strstr($name, 'HTTP_')) {
                $name = strtolower(str_replace(array('HTTP_', '_'), array('', '-'), $name));
                if (!in_array($name, $denyList)) {
                    $result[$name] = $value;
                }

            }
        }

        if (in_array(self::HEADER_COOKIE, $denyList)) {
            $result[self::HEADER_COOKIE] = static::filterCookies(isset($result[self::HEADER_COOKIE]) ? $result[self::HEADER_COOKIE] : '');
        }
        return $result;
    }

    /**
     * @param array<string> $headers
     * @param array<string> $allowedHeaders
     * @return array<string>
     */
    public static function filterHeaders($headers, $allowedHeaders)
    {
        $result = array();
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), $allowedHeaders)) {
                $result[$name] = $value;
            }
        }
        return $result;
    }

    public static function filterCookies($cookiesString)
    {
        $cookiesString = preg_replace('/PHPSESSID=.*?;/si', '', $cookiesString);
        $cookiesString = preg_replace('/[_]?subid=.*?;/si', '', $cookiesString);
        $cookiesString = preg_replace('/[_]?token=.*?;/si', '', $cookiesString);
        return trim($cookiesString);
    }

    public static function filterServerParams($serverEnv, $names)
    {
        $result = array();
        foreach ($names as $name) {
            if (isset($serverEnv[$name])) {
                $result[strtolower($name)] = $serverEnv[$name];
            }
        }
        return $result;
    }

    public static function convertTtlToTimestamp($ttl)
    {
        return time() + 60 * 60 * $ttl;
    }

    public static function findCookieDomain()
    {
        if (isset($_SERVER['HTTP_HOST']) && substr_count($_SERVER['HTTP_HOST'], '.') < 3) {
            $host = '.' . str_replace('www.', '', $_SERVER['HTTP_HOST']);
        } else {
            $host = null;
        }
        return $host;
    }

    /**
     * @var array<string> $headers
     * @return array<string>
     */
    public static function flattenHeaders($headers)
    {
        $result = array();
        foreach ($headers as $name => $value) {
            $result[] = $name . ': ' . $value;
        }
        return $result;
    }

    public static function isHttps()
    {
        return (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) || !empty($_SERVER['HTTPS']);
    }

    /**
     * @param array $server
     * @return string|null
     */
    public static function buildRequestedUrl($server)
    {
        if (empty($server['HTTP_HOST'])) {
            return null;
        }
        $isHttps = (isset($server['SERVER_PORT']) && $server['SERVER_PORT'] == 443) || !empty($server['HTTPS']);
        $scheme = $isHttps ? 'https' : 'http';
        $requestUri = isset($server['REQUEST_URI']) ? $server['REQUEST_URI'] : '';
        return $scheme . '://' . $server['HTTP_HOST'] . $requestUri;
    }

    public static function secureParams($params)
    {
        $secure = array('token');
        foreach ($params as $name => $value) {
            if (in_array($name, $secure)) {
                $params[$name] = str_repeat('*', strlen($value));
            }
        }
        return $params;
    }

    public static function convertCookie($cookies)
    {
        $result = array();
        if (isset($cookies)) {
            foreach ($cookies as $cookie) {
                if (empty($cookie->name)) {
                    throw new KClientError('You\'re using wrong API version (minimal 4)');
                }
                $kCookie = new KCookie(
                    $cookie->name,
                    $cookie->value,
                    isset($cookie->expires) ? $cookie->expires : null,
                    isset($cookie->path) ? $cookie->path : null,
                    isset($cookie->secure) ? $cookie->secure : null,
                    isset($cookie->httponly) ? $cookie->httponly : null,
                    isset($cookie->samesite) ? $cookie->samesite : null
                );
                $result[] = $kCookie;
            }
        }
        return $result;
    }

    /**
     * @param array<string> $headers
     */
    public static function removeHeaders($headers)
    {
        foreach ($headers as $header) {
            header_remove($header);
        }
    }

    /**
     * @param string $filename
     * @param array<string> $allowList
     * @return bool
     */
    public static function includeInAllowlist($filename, $allowList)
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        return in_array($extension, $allowList);
    }
}

interface KDispatcherInterface
{
    /**
     * @param array $headers
     * @return void
     */
    public function sendHeaders($headers);

    /**
     * @param string $contentType
     */
    //public function sendContentType($contentType);

    /**
     * @param int $code
     * @return void
     */
    public function sendHttpStatus($code = null);

    /**
     * @param KCookie $cookie
     * @param string $domain
     * @param bool $secure
     * @return void
     */
    public function setCookie(KCookie $cookie, $domain, $secure);

    /**
     * @param string $body
     * @return void
     */
    public function sendBody($body);

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    public function setSessionValue($name, $value);

    /**
     * @return void
     */
    public function startSession();

    /**
     * @return void
     */
    public function stop();
}

class KDispatcher implements KDispatcherInterface
{
    public function sendHeaders($headers)
    {
        if (!empty($headers)) {
            foreach ($headers as $headerName => $header) {
                if (is_string($headerName)) {
                    $header = $headerName . ': ' . $header;
                }
                header($header);
            }
        }
    }

    //public function sendContentType($contentType)
    /*{
        if (!empty($contentType)) {
            $header = 'content-type: ' . $contentType;
            header($header);
        }
    }*/

    public function sendHttpStatus($code = null)
    {
        http_response_code($code);
    }

    public function sendBody($body)
    {
        echo $body;
    }

    public function setCookie(KCookie $kCookie, $domain, $secure)
    {
        $secure = $kCookie->secure === true;
        $httpOnly = $kCookie->httpOnly === true;

        return setcookie(
            $kCookie->name,
            $kCookie->value,
            strtotime($kCookie->expires),
            $kCookie->path,
            $domain,
            $secure,
            $kCookie->httpOnly
        );
    }

    /**
     * @param string $name
     * @param string|int $value
     * @return void
     */
    public function setSessionValue($name, $value)
    {
        $this->startSession();
        $_SESSION[$name] = $value;
    }

    public function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function stop()
    {
        exit(0);
    }
}


interface KHttpClientInterface
{
    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $params
     * @param array<string, string> $options
     * @return KResponse
     */
    public function request($method, $url, $params, $options);
}

class KHttpClient implements KHttpClientInterface
{
    const POST = 'POST';
    const GET = 'GET';

    /**
     * @throws KClientError
     */
    public function request($method, $url, $params, $options)
    {
        if (!in_array('curl', get_loaded_extensions())) {
            return json_encode(array('error' => 'Curl extension must be installed'));
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_NOBODY, 0);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        foreach ($options as $name => $value) {
            curl_setopt($ch, $name, $value);
        }

        if ($method == self::POST) {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            $queryString = http_build_query($params);
            $requestUrl = $url . '?' . $queryString;
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_URL, $requestUrl);
        }

        $result = curl_exec($ch);

        if (curl_error($ch)) {
            throw new KClientError(curl_error($ch), curl_errno($ch));
        }

        $info = curl_getinfo($ch);

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = $this->parseHeaders(substr($result, 0, $headerSize));
        $body = substr($result, $headerSize);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return new KResponse($status, $headers, $body);
    }

    /**
     * @param string $headers Example: "Connection: close; Content-Type: text/html; charset=UTF-8"
     * @return array<string, string>
     */
    private function parseHeaders($headers)
    {
        $headers = explode("\r\n", $headers);
        $result = array();
        foreach ($headers as $header) {
            $header = explode(':', $header, 2);
            if (count($header) > 1) {
                $result[trim($header[0])] = trim($header[1]);
            }
        }
        return $result;
    }
}

class KResponse
{
    const GZIP_LEVEL = 6;
    public $status;
    public $headers;
    public $body;
    private $gzipLevel;

    public function __construct($status, $headers, $body, $gzipLevel = self::GZIP_LEVEL)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->gzipLevel = $gzipLevel;
    }

    /**
     * @return bool
     */
    public function isGzipped()
    {
        return isset($this->headers['Content-Encoding']) && $this->headers['Content-Encoding'] == 'gzip';
    }

    /**
     * @return string
     */
    public function compressedBody()
    {
        return gzencode($this->body, $this->gzipLevel);
    }
}

/**
 * Class KApiResponse
 */
class KApiResult
{
    public $body;

    public $status;

    public $error;
    /**
     * @var array<KCookie>
     */
    public $cookies;
    /**
     * @var int
     */
    public $ttl;

    public $contentType;
    /**
     * @var array
     */
    public $headers;
    /**
     * @var KApiResultInfo
     */
    public $info;
    public $log;

    /**
     * @param string $body
     * @param string|null $error
     * @param int $status
     * @param array<KCookie> $cookies
     * @param int $ttl
     * @param string $contentType
     * @param array $headers
     * @param KApiResultInfo $info
     * @param array|null $log
     */
    public function __construct($body, $error, $status, $cookies, $ttl, $contentType, $headers, $info, $log)
    {
        $this->body = $body;
        $this->status = $status;
        $this->cookies = $cookies;
        $this->ttl = $ttl;
        $this->error = $error;
        $this->contentType = $contentType;
        $this->headers = $headers;
        $this->info = $info;
        $this->log = $log;
    }

    /**
     * @param stdClass $stdClass
     * @return KApiResult
     */
    public static function fromStdClass($stdClass)
    {
        return new self(
            isset($stdClass->body) ? $stdClass->body : null,
            isset($stdClass->error) ? $stdClass->error : null,
            isset($stdClass->status) ? $stdClass->status : null,
            isset($stdClass->cookies) ? KHelper::convertCookie($stdClass->cookies) : null,
            isset($stdClass->ttl) ? $stdClass->ttl : null,
            isset($stdClass->contentType) ? $stdClass->contentType : null,
            isset($stdClass->headers) ? $stdClass->headers : null,
            isset($stdClass->info) ? KApiResultInfo::fromStdClass($stdClass->info) : null,
            isset($stdClass->log) ? $stdClass->log : null
        );
    }

    public function asArray()
    {
        return array(
            'body' => $this->body,
            'error' => $this->error,
            'status' => $this->status,
            'cookies' => $this->cookies,
            'ttl' => $this->ttl,
            'content_type' => $this->contentType,
            'headers' => $this->headers,
            'info' => isset($this->info) ? $this->info->asArray() : array(),
            'log' => $this->log
        );
    }
}

/**
 * Class KApiResponseInfo
 */
class KApiResultInfo
{
    /**
     * @var int
     */
    public $campaignId;
    /**
     * @var int
     */
    public $streamId;
    public $subId;
    /**
     * @var string|null
     */
    public $type;
    /**
     * @var string|null
     */
    public $url;
    /**
     * @var string|null
     */
    public $token;
    /**
     * @var bool
     */
    public $bot;
    /**
     * @var array<string, bool>
     */
    public $uniqueness;

    /**
     * KApiResponseInfo constructor.
     *
     * @param int $campaignId
     * @param int $streamId
     * @param string $subId
     * @param string $type
     * @param string $url
     * @param string $token
     * @param bool $bot
     * @param array<string, bool> $uniqueness
     */
    public function __construct($campaignId, $streamId, $subId, $type, $url, $token, $bot, $uniqueness)
    {
        $this->campaignId = $campaignId;
        $this->streamId = $streamId;
        $this->subId = $subId;
        $this->type = $type;
        $this->url = $url;
        $this->token = $token;
        $this->bot = $bot;
        $this->uniqueness = $uniqueness;
    }

    /**
     * @param stdClass $stdClass
     * @return KApiResultInfo
     */
    public static function fromStdClass($stdClass)
    {
        return new self(
            isset($stdClass->campaign_id) ? $stdClass->campaign_id : null,
            isset($stdClass->stream_id) ? $stdClass->stream_id : null,
            isset($stdClass->sub_id) ? $stdClass->sub_id : null,
            isset($stdClass->type) ? $stdClass->type : null,
            isset($stdClass->url) ? $stdClass->url : null,
            isset($stdClass->token) ? $stdClass->token : null,
            isset($stdClass->is_bot) ? $stdClass->is_bot : false,
            isset($stdClass->uniqueness) ? $stdClass->uniqueness : array()
        );
    }

    public function asArray()
    {
        return array(
            'campaign_id' => $this->campaignId,
            'stream_id' => $this->streamId,
            'sub_id' => $this->subId,
            'type' => $this->type,
            'url' => $this->url,
            'token' => $this->token,
            'is_bot' => $this->bot,
            'uniqueness' => $this->uniqueness
        );
    }
}

class KCookie
{
    public $name;
    public $value;
    public $expires;
    public $path;
    public $secure;
    public $httpOnly;
    public $sameSite;

    /**
     * KCookie constructor.
     *
     * @param string $name
     * @param mixed $value
     * @param string|null $expires
     * @param string|null $path
     * @param bool|null $secure
     * @param bool|null $httpOnly
     * @param string|null $sameSite
     */
    public function __construct(
        $name,
        $value,
        $expires = null,
        $path = null,
        $secure = null,
        $httpOnly = null,
        $sameSite = null
    ) {
        $this->name = $name;
        $this->value = $value;
        $this->expires = $expires;
        $this->path = $path;
        $this->secure = $secure;
        $this->httpOnly = $httpOnly;
        $this->sameSite = $sameSite;
    }
}

class KClientError extends Exception
{
    const ERROR_UNKNOWN = 'UNKNOWN';

    public function getHumanCode()
    {
        switch ($this->getCode()) {
            case CURLE_HTTP_RETURNED_ERROR:
                preg_match(
                    "/The requested URL returned error: (?'errorCode'\d+).*$/",
                    $this->getMessage(),
                    $matches
                );

                $errorCode = isset($matches['errorCode']) ? $matches['errorCode'] : 'HTTP_ERROR_' . self::ERROR_UNKNOWN;
                return "[REQ_ERR: {$errorCode}]";
            case CURLE_UNSUPPORTED_PROTOCOL:
                return "[REQ_ERR: UNSUPPORTED_PROTOCOL]";
            case CURLE_FAILED_INIT:
                return "[REQ_ERR: FAILED_INIT]";
            case CURLE_URL_MALFORMAT:
                return "[REQ_ERR: BAD_URL]";
            case CURLE_COULDNT_RESOLVE_PROXY:
                return "[REQ_ERR: COULDNT_RESOLVE_PROXY]";
            case CURLE_COULDNT_RESOLVE_HOST:
                return "[REQ_ERR: COULDNT_RESOLVE_HOST]";
            case CURLE_COULDNT_CONNECT:
                return "[REQ_ERR: COULDNT_CONNECT]";
            case CURLE_PARTIAL_FILE:
                return "[REQ_ERR: PARTIAL_FILE]";
            case CURLE_READ_ERROR:
                return "[REQ_ERR: READ_ERROR]";
            case CURLE_OUT_OF_MEMORY:
                return "[REQ_ERR: OUT_OF_MEMORY]";
            case CURLE_OPERATION_TIMEDOUT:
                return "[REQ_ERR: OPERATION_TIMEDOUT]";
            case CURLE_HTTP_POST_ERROR:
                return "[REQ_ERR: HTTP_POST_ERROR]";
            case CURLE_BAD_FUNCTION_ARGUMENT:
                return "[REQ_ERR: BAD_FUNCTION_ARGUMENT]";
            case CURLE_TOO_MANY_REDIRECTS:
                return "[REQ_ERR: TOO_MANY_REDIRECTS]";
            case CURLE_GOT_NOTHING:
                return "[REQ_ERR: GOT_NOTHING]";
            case CURLE_SEND_ERROR:
                return "[REQ_ERR: SEND_ERROR]";
            case CURLE_RECV_ERROR:
                return "[REQ_ERR: RECV_ERROR]";
            case CURLE_BAD_CONTENT_ENCODING:
                return "[REQ_ERR: BAD_CONTENT_ENCODING]";
            case CURLE_SSL_CACERT:
            case CURLE_SSL_CACERT_BADFILE:
            case CURLE_SSL_CERTPROBLEM:
            case CURLE_SSL_CIPHER:
            case CURLE_SSL_CONNECT_ERROR:
            case CURLE_SSL_ENGINE_NOTFOUND:
            case CURLE_SSL_ENGINE_SETFAILED:
            case CURLE_SSL_PEER_CERTIFICATE:
            case CURLE_SSL_PINNEDPUBKEYNOTMATCH:
                return "[REQ_ERR: SSL]";
            case CURLE_OK:
                return '';
            default:
                return "[REQ_ERR: " . self::ERROR_UNKNOWN . "]";
        }
    }

}

class_alias('KClient', 'KClickClient');
