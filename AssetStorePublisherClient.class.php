<?php
namespace AssetStore;

class AssetStoreException extends \Exception { }

class Client {
    const LOGOUT_URL = 'https://publisher.assetstore.unity3d.com/logout';
    const SALES_URL = 'https://publisher.assetstore.unity3d.com/sales.html';
    const USER_OVERVIEW_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/user/overview.json';
    const PUBLISHER_OVERVIEW_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher/overview.json';
    const SALES_PERIODS_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/months/{publisherId}.json';
    const SALES_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/sales/{publisherId}/{year}{month}.json';
    const DOWNLOADS_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/downloads/{publisherId}/{year}{month}.json';
    const INVOICE_VERIFY_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/verify-invoice/{publisherId}/{invoiceId}.json';
    const REVENUE_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/revenue/{publisherId}.json';
    const PACKAGES_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/management/packages.json';
    const API_KEY_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/api-key/{publisherId}.json';
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 6.3; rv:27.0) Gecko/20100101 Firefox/27.0';
    const TOKEN_COOKIE_NAME = 'kharma_session';

    const TFA_CODE_REQUESTED = 'TFA_CODE_REQUESTED';

    private $loginToken;
    private $isLoggedIn = false;
    private $cookies = Array();
    private $userInfoOverview = null;
    private $publisherInfoOverview = null;
    private $tfaResumeData = null;

    public function LoginWithToken($token) {
        $this->AssertIsNotLoggedIn();

        $this->loginToken = $token;
        $this->isLoggedIn = true;
        $this->cookies[self::TOKEN_COOKIE_NAME] = $this->loginToken;
    }

    public function Login($user, $password, $tfaResumeData = null, $tfaCode = null) {
        $this->AssertIsNotLoggedIn();

        $token = $this->GetLoginToken($user, $password, $tfaResumeData, $tfaCode);
        if ($token == self::TFA_CODE_REQUESTED) {
            return self::TFA_CODE_REQUESTED;
        }

        $this->LoginWithToken($token);

        return $token;
    }

    public function Logout() {
        $this->AssertIsLoggedIn();

        $this->GetSimpleData(Array('url' => self::LOGOUT_URL), $resultData, $resultHttpCode);
        self::AssertHttpCode('Logout failed, error code {code}', $resultHttpCode);

        unset($this->cookies[self::TOKEN_COOKIE_NAME]);
        $this->userInfoOverview = null;
        $this->publisherInfoOverview = null;
        $this->isLoggedIn = false;
    }

    public function IsLoggedIn() {
        return $this->isLoggedIn;
    }

    public function GetUserInfo() {
        $this->AssertIsLoggedIn();

        if ($this->userInfoOverview === null) {
            $result = $this->GetSimpleData(Array('url' => self::USER_OVERVIEW_JSON_URL), $resultData, $resultHttpCode);
            self::AssertHttpCode('Fetching user data failed, error code {code}', $resultHttpCode);

            $this->userInfoOverview = json_decode($resultData);
        }

        return $this->userInfoOverview;
    }

    public function GetTfaResumeData() {
        return $this->tfaResumeData;
    }

    public function GetPublisherInfo() {
        $this->AssertIsLoggedIn();

        if ($this->publisherInfoOverview === null) {
            $result = $this->GetSimpleData(Array('url' => self::PUBLISHER_OVERVIEW_JSON_URL), $resultData, $resultHttpCode);
            self::AssertHttpCode('Fetching publisher data failed, error code {code}', $resultHttpCode);

            $publisherInfoObject = json_decode($resultData);
            $this->publisherInfoOverview = new PublisherInfo($publisherInfoObject);
        }

        return $this->publisherInfoOverview;
    }

    public function FetchApiKey() {
        $url = str_replace('{publisherId}', $this->GetPublisherInfo()->GetId(), self::API_KEY_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url), $resultData, $resultHttpCode);
        self::AssertHttpCode('Fetching API key failed, error code {code}', $resultHttpCode);

        $keyDataObject = json_decode($resultData);
        return $keyDataObject->api_key;
    }

    public function FetchSalesPeriods() {
        $this->AssertIsLoggedIn();

        $url = str_replace('{publisherId}', $this->GetPublisherInfo()->GetId(), self::SALES_PERIODS_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url), $resultData, $resultHttpCode);
        self::AssertHttpCode('Fetching sales periods failed, error code {code}', $resultHttpCode);

        $salesPeriods = json_decode($resultData);

        $infoArray = Array();
        foreach ($salesPeriods->periods as $value) {
            $infoArray[] = new SalesPeriod($value);
        }

        return $infoArray;
    }

    public function FetchRevenue() {
        $this->AssertIsLoggedIn();

        $url = str_replace('{publisherId}', $this->GetPublisherInfo()->GetId(), self::REVENUE_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url), $resultData, $resultHttpCode);
        self::AssertHttpCode('Fetching sales periods failed, error code {code}', $resultHttpCode);

        $infoObject = json_decode($resultData);

        $infoArray = Array();
        foreach ($infoObject->aaData as $value) {
            $infoArray[] = new RevenueInfo($value);
        }

        return $infoArray;
    }

    public function FetchPackages() {
        $this->AssertIsLoggedIn();

        $url = str_replace('{publisherId}', $this->GetPublisherInfo()->GetId(), self::PACKAGES_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url), $resultData, $resultHttpCode);
        self::AssertHttpCode('Fetching packages failed, error code {code}', $resultHttpCode);

        $infoObject = json_decode($resultData);

        $infoArray = Array();
        foreach ($infoObject->packages as $value) {
            $infoArray[] = new PackageInfo($value);
        }

        return $infoArray;
    }

    public function FetchSales($year, $month) {
        $this->AssertIsLoggedIn();

        $year = (int) $year;
        $month = (int) $month;

        if ($year < 2010) {
            throw new AssetStoreException('Year must be after 2009');
        }
        if ($month > 12 || $month < 1) {
            throw new AssetStoreException('Month must be an integer between 1 and 12');
        }

        $month = str_pad($month, 2, '0', STR_PAD_LEFT);
        $url = str_replace(Array('{publisherId}', '{year}', '{month}'),
                           Array($this->GetPublisherInfo()->GetId(), $year, $month),
                           self::SALES_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url), $resultData, $resultHttpCode);
        self::AssertHttpCode('Fetching sales failed, error code {code}', $resultHttpCode);

        $salesInfoObject = json_decode($resultData);
        $salesInfo = Array();

        foreach ($salesInfoObject->aaData as $key => $value) {
            $value['shortUrl'] = $salesInfoObject->result[$key]->short_url;
            $salesInfo[] = new PackageSalesInfo($value);
        }

        return new PeriodSalesInfo($salesInfo, $this->GetPublisherInfo()->GetPayoutCut());
    }

    public function FetchDownloads($year, $month) {
        $this->AssertIsLoggedIn();

        $year = (int) $year;
        $month = (int) $month;

        if ($year < 2010) {
            throw new AssetStoreException('Year must be after 2009');
        }
        if ($month > 12 || $month < 1) {
            throw new AssetStoreException('Month must be an integer between 1 and 12');
        }

        $month = str_pad($month, 2, '0', STR_PAD_LEFT);
        $url = str_replace(Array('{publisherId}', '{year}', '{month}'),
                           Array($this->GetPublisherInfo()->GetId(), $year, $month),
                           self::DOWNLOADS_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url), $resultData, $resultHttpCode);
        self::AssertHttpCode('Fetching downloads failed, error code {code}', $resultHttpCode);

        $downloadsInfoObject = json_decode($resultData);
        $downloadsInfo = Array();

        foreach ($downloadsInfoObject->aaData as $key => $value) {
            $value['shortUrl'] = $downloadsInfoObject->result[$key]->short_url;
            $downloadsInfo[] = new PackageDownloadsInfo($value);
        }

        return new PeriodDownloadsInfo($downloadsInfo);
    }

    public function VerifyInvoice($invoiceNumbers) {
        $this->AssertIsLoggedIn();

        if (!is_array($invoiceNumbers)) {
            $invoiceNumbers = preg_split("#[\s,]+#", $invoiceNumbers, 0, PREG_SPLIT_NO_EMPTY);
        }
        foreach ($invoiceNumbers as &$value) {
            $value = preg_replace('#[^0-9]#', '', $value);
        }
        unset($value);
        $invoiceNumbers = implode(',', $invoiceNumbers);
        $url = str_replace(Array('{publisherId}', '{invoiceId}'),
                           Array($this->GetPublisherInfo()->GetId(), urlencode($invoiceNumbers)),
                           self::INVOICE_VERIFY_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url), $resultData, $resultHttpCode);
        self::AssertHttpCode('Invoice verification failed, error code {code}', $resultHttpCode);

        $invoiceInfoObject = json_decode($resultData);
        $invoiceInfo = Array();
        foreach ($invoiceInfoObject->aaData as $value) {
            $invoiceInfo[] = new InvoiceInfo($value);
        }

        return $invoiceInfo;
    }

    private function GetLoginToken($user, $password, $tfaResumeData, $tfaCode) {
        $genesisAuthFrontendCookieName = '_genesis_auth_frontend_session';

        if ($tfaResumeData == null) {
            // Phase 1: get Unity authorize redirect URL
            self::ExecuteCurlQuery(
                    Array('url' => self::SALES_URL,
                          'getHeaders' => true),
                    $resultData,
                    $resultHttpCode
                );

            self::AssertHttpCode('Login failed at phase 1, error code {code}', $resultHttpCode);

            $resultHeaders = self::GetHeadersFromHttpResponse($resultData);
            $redirectUrl = self::GetHeaderFromHeaderArray($resultHeaders, 'Location');
            $redirectUrl = $redirectUrl['value'];

            // Phase 2: get Unity ID redirect URL
            self::ExecuteCurlQuery(
                    Array('url' => $redirectUrl,
                          'getHeaders' => true),
                    $resultData,
                    $resultHttpCode
                );

            self::AssertHttpCode('Login failed at phase 2, error code {code}', $resultHttpCode);

            $resultHeaders = self::GetHeadersFromHttpResponse($resultData);
            $redirectUrl = self::GetHeaderFromHeaderArray($resultHeaders, 'Location');
            $redirectUrl = $redirectUrl['value'];

            // Phase 3: load Unity ID authorization page
            self::ExecuteCurlQuery(
                    Array('url' => $redirectUrl,
                          'getHeaders' => true),
                    $resultData,
                    $resultHttpCode
                );

            self::AssertHttpCode('Login failed at phase 3, error code {code}', $resultHttpCode);

            $resultHeaders = self::GetHeadersFromHttpResponse($resultData);

            $setCookieHeader = self::GetHeaderFromHeaderArray($resultHeaders, 'Set-Cookie', $genesisAuthFrontendCookieName);

            $parsedCookie = self::ParseCookies($setCookieHeader['value']);
            $genesisAuthFrontendCookieValue = @urldecode($parsedCookie[0][$genesisAuthFrontendCookieName]);
            if ($genesisAuthFrontendCookieValue == null)
                throw new AssetStoreException($genesisAuthFrontendCookieName . ' cookie not found (phase 3)');

            // Get authenticity token from HTML
            $authenticityTokenMatches = Array();
            preg_match('#<input type="hidden" name="authenticity_token" value="(.+)" />#', $resultData, $authenticityTokenMatches);
            $authenticityToken = @$authenticityTokenMatches[1];
            if ($authenticityToken == null)
                throw new AssetStoreException('Page authenticity token not found (phase 3)');

            // Phase 4: send login data and get authorization URL
            $loginQuery = Array(
                    'utf8' => '✓',
                    '_method' => 'put',
                    'authenticity_token' => $authenticityToken,
                    'conversations_create_session_form[email]' => $user,
                    'conversations_create_session_form[password]' => $password,
                    'conversations_create_session_form[remember_me]' => 'true',
                    'commit' => 'Log in'
                );

            self::ExecuteCurlQuery(
                    Array('url' => $redirectUrl,
                          'query' => $loginQuery,
                          'cookies' => Array($genesisAuthFrontendCookieName => $genesisAuthFrontendCookieValue),
                          'getHeaders' => true),
                    $resultData,
                    $resultHttpCode
                );

            self::AssertHttpCode('Login failed at phase 4, error code {code}', $resultHttpCode);

            $resultHeaders = self::GetHeadersFromHttpResponse($resultData);
            $redirectUrl = self::GetHeaderFromHeaderArray($resultHeaders, 'Location');
            $redirectUrl = $redirectUrl['value'];

            // Phase 5: get "bounce" page URL
            self::ExecuteCurlQuery(
                    Array('url' => $redirectUrl,
                          'getHeaders' => true),
                    $resultData,
                    $resultHttpCode
                );

            self::AssertHttpCode('Login failed at phase 5, error code {code}', $resultHttpCode);

            $resultHeaders = self::GetHeadersFromHttpResponse($resultData);
            $resultBody = self::GetResponseBodyFromHttpResponse($resultData);

            // Phase 6: check for TFA page
            if (strpos($resultBody, 'conversations_email_tfa_required_form[resend]') !== false) {
                $tfaFormActionMatches = Array();
                preg_match('#id="new_conversations_email_tfa_required_form" action="(.+?)"#', $resultBody, $tfaFormActionMatches);
                $this->tfaResumeData = Array (
                    'tfaFormAction' => 'https://id.unity.com' . $tfaFormActionMatches[1],
                    'cookies' => Array($genesisAuthFrontendCookieName => $genesisAuthFrontendCookieValue),
                    'authenticityToken' => $authenticityToken
                );
                return self::TFA_CODE_REQUESTED;
            }
        } else {
            // Phase 7: enter TFA code
            $genesisAuthFrontendCookieValue = $tfaResumeData['cookies'][$genesisAuthFrontendCookieName];
            $authenticityToken = $tfaResumeData['authenticityToken'];

            $tfaQuery = Array(
                'utf8' => '✓',
                '_method' => 'put',
                'authenticity_token' => $authenticityToken,
                'conversations_email_tfa_required_form[code]' => $tfaCode,
                'commit' => 'Verify'
            );

            self::ExecuteCurlQuery(
                Array('url' => $tfaResumeData['tfaFormAction'],
                      'query' => $tfaQuery,
                      'cookies' => Array($genesisAuthFrontendCookieName => $genesisAuthFrontendCookieValue),
                      'getHeaders' => true),
                $resultData,
                $resultHttpCode
            );

            self::AssertHttpCode('Login failed at phase 7, error code {code}', $resultHttpCode);

            $resultHeaders = self::GetHeadersFromHttpResponse($resultData);
            $resultBody = self::GetResponseBodyFromHttpResponse($resultData);

            $redirectUrl = self::GetHeaderFromHeaderArray($resultHeaders, 'Location');
            $redirectUrl = $redirectUrl['value'];

            // Phase 8: get "bounce" page
            self::ExecuteCurlQuery(
                Array('url' => $redirectUrl,
                      'getHeaders' => true),
                $resultData,
                $resultHttpCode
            );

            $resultHeaders = self::GetHeadersFromHttpResponse($resultData);
            $resultBody = self::GetResponseBodyFromHttpResponse($resultData);

            //var_dump($resultHeaders);
            //var_dump($resultBody);
        }

        $bounceUrlMatches = Array();
        preg_match('#window\.location\.href \= \"(.+)\"#', $resultBody, $bounceUrlMatches);

        $redirectUrl = $bounceUrlMatches[1];

        // Phase 8: get "bounce" page
        self::ExecuteCurlQuery(
                Array('url' => $redirectUrl,
                      'getHeaders' => true),
                $resultData,
                $resultHttpCode
            );

        self::AssertHttpCode('Login failed at phase 8, error code {code}', $resultHttpCode);

        $resultHeaders = self::GetHeadersFromHttpResponse($resultData);
        $redirectUrl = self::GetHeaderFromHeaderArray($resultHeaders, 'Location');
        $redirectUrl = $redirectUrl['value'];

        $kharmaSessionSetCookieHeader = self::GetHeaderFromHeaderArray($resultHeaders, 'Set-Cookie', self::TOKEN_COOKIE_NAME);
        if ($kharmaSessionSetCookieHeader == null)
            throw new AssetStoreException(self::TOKEN_COOKIE_NAME . ' cookie not found (phase 8)');

        $kharmaSessionParsedCookie = self::ParseCookies($kharmaSessionSetCookieHeader['value']);
        $kharmaSession = @urldecode($kharmaSessionParsedCookie[0][self::TOKEN_COOKIE_NAME]);
        if ($kharmaSession == null)
            throw new AssetStoreException(self::TOKEN_COOKIE_NAME . ' cookie could not be parsed (phase 8)');

        $kharmaSession = trim($kharmaSession);
        return $kharmaSession;
    }

    private function GetSimpleData($params, &$resultData, &$resultHttpCode) {
        $params['cookies'] = $this->cookies;
        return self::ExecuteCurlQuery($params, $resultData, $resultHttpCode);
    }

    private function AssertIsNotLoggedIn() {
        if ($this->IsLoggedIn())
            throw new AssetStoreException('Login already performed, can\'t login multiple times');
    }

    private function AssertIsLoggedIn() {
        if (!$this->IsLoggedIn())
            throw new AssetStoreException('Can\'t execute operation when not logged in');
    }

    private static function AssertHttpCode($message, $code) {
        $isError = HttpUtilities::IsErrorCode($code);
        $isNotKnownCode = !HttpUtilities::IsKnownCode($code);
        if (!($isError || $isNotKnownCode))
            return;

        $httpStatusMmessage = "Unknown HTTP response code";
        if ($isError) {
            $httpStatusMmessage = HttpUtilities::GetStatusMessage($code);
        }

        throw new AssetStoreException(str_replace('{code}', $code . ' (' . $httpStatusMmessage . ')', $message), $code);
    }

    private static function ExecuteCurlQuery($params, &$resultData, &$resultHttpCode) {
        $ch = self::SetupCurlQueryInternal($params);
        $resultData = curl_exec($ch);
        $curlError = curl_error($ch);
        $resultHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!empty($curlError))
            throw new AssetStoreException("cURL error occured: {$curlError}");
    }

    private static function SetupCurlQueryInternal($params) {
        if (isset($params['query'])) {
            $query = http_build_query($params['query']);
        }

        $cookies = isset($params['cookies']) ? http_build_query($params['cookies'], '', '; ') : null;
        $headers = Array();
        if (isset($params['headers'])) {
            $headers = array_merge($headers, $params['headers']);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_URL, $params['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, isset($params['getHeaders']) && $params['getHeaders']);
        if (isset($query)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }
        if ($cookies != null) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_REFERER, isset($params['referer']) ? $params['referer'] : $params['url']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        return $ch;
    }

    private static function GetHeaderFromHeaderArray($cookieArray, $cookieKey, $cookieValueStart = null) {
        foreach ($cookieArray as $value) {
            if ($value['key'] == $cookieKey && ($cookieValueStart == null || strpos($value['value'], $cookieValueStart) === 0))
                return $value;
        }

        return null;
    }

    private static function GetHeadersFromHttpResponse($response) {
        $headers = array();
        $headerText = substr($response, 0, strpos($response, "\r\n\r\n"));

        foreach (explode("\r\n", $headerText) as $i => $line) {
            if ($i === 0) {
                // HTTP response code, ignore
                //$headers['httpCode'] = $line;
            } else {
                list($key, $value) = explode(': ', $line);
                $headers[] = Array('key' => $key, 'value' => $value);
            }
        }

        return $headers;
    }

    private static function GetResponseBodyFromHttpResponse($response) {
        return substr($response, strpos($response, "\r\n\r\n"));
    }

    // https://gist.github.com/pokeb/10590
    private static function ParseCookies($header) {
        $cookies = Array();
        $cookie = Array();
        $parts = explode('=', $header);
        $partsCount = count($parts);

        for ($i = 0; $i < $partsCount; $i++) {
            $part = $parts[$i];
            if ($i==0) {
                $key = $part;
                continue;
            } elseif ($i == $partsCount - 1) {
                $cookie[$key] = $part;
                $cookies[] = $cookie;
                continue;
            }

            $comps = explode(" ", $part);
            $newKey = $comps[count($comps) - 1];
            $value = substr($part, 0, strlen($part) - strlen($newKey) - 1);
            $terminator = substr($value, -1);
            $value = substr($value, 0, strlen($value) - 1);
            $cookie[$key] = $value;
            if ($terminator == ",") {
                $cookies[] = $cookie;
                $cookie = Array();
            }

            $key = $newKey;
        }

        return $cookies;
    }
}

abstract class ParsedData {
    protected $data = Array();

    protected static function ParseDate($date) {
        if(empty($date))
            return null;

        return \DateTime::createFromFormat('Y-m-d', $date)->getTimestamp();
    }

    protected static function ParseDateTime($dateTime) {
        if(empty($dateTime))
            return null;

        return \DateTime::createFromFormat('Y-m-d H:i:s', $dateTime)->getTimestamp();
    }

    protected static function ParseCurrency($value) {
        if(empty($value))
            return null;

        return (float) preg_replace('#[^0-9,.-]#', '', $value);
    }
}

trait ConvertableObject {
    public function ToArray() {
        return self::ObjectToArray($this);
    }

    // Based on
    // http://blog.republicofone.com/2009/08/php-json-object-reflection-recursion/
    protected static function ObjectToArray($object) {
        $reflected = new \ReflectionClass($object);
        $data = Array();

        $methods = $reflected->getMethods();
        foreach ($methods as $method) {
            if (strpos($method->name, "Get") !== 0 || !$method->isPublic() || $method->isStatic())
                continue;

            $name = substr($method->name, 3);
            $name[0] = strtolower($name[0]);
            $value = $method->invoke($object);
            $valueType = gettype($value);

            switch ($valueType) {
                case 'object':
                    $value = self::ObjectToArray($value);
                    break;

                case 'array':
                    $valueArray = Array();
                    foreach($value as $arrayElement){
                        $valueArray[] = self::ObjectToArray($arrayElement);
                    }

                    $value = $valueArray;
                    break;
            }

            $data[$name] = $value;
        }

        return $data;
    }
}

class PublisherInfo extends ParsedData {
    use ConvertableObject;

    function __construct($data) {
        $data = $data->overview;
        $this->data = Array(
                            'id' => (int) $data->id,
                            'name' => $data->name,
                            'description' => $data->description,
                            'rating' => (int) $data->rating->average,
                            'ratingCount' => (int) $data->rating->count,
                            'payoutCut' => $data->payout_cut,
                            'publisherShortUrl' => $data->short_url,
                            'siteUrl' => $data->url,
                            'supportUrl' => $data->support_url,
                            'supportEmail' => $data->support_email
                            );
    }

    public function GetId() {
        return $this->data['id'];
    }

    public function GetName() {
        return $this->data['name'];
    }

    public function GetDescription() {
        return $this->data['description'];
    }

    public function GetRating() {
        return $this->data['rating'];
    }

    public function GetRatingCount() {
        return $this->data['ratingCount'];
    }

    public function GetPayoutCut() {
        return $this->data['payoutCut'];
    }

    public function GetPublisherShortUrl() {
        return $this->data['publisherShortUrl'];
    }

    public function GetSiteUrl() {
        return $this->data['siteUrl'];
    }

    public function GetSupportUrl() {
        return $this->data['supportUrl'];
    }

    public function GetSupportEmail() {
        return $this->data['supportEmail'];
    }
}

class RevenueInfo extends ParsedData {
    use ConvertableObject;

    const TypeUnknown = -1;
    const TypeRevenue = 1;
    const TypePayout = 2;

    function __construct($data) {
        $infoType = self::TypeUnknown;
        if (stripos($data[1], 'revenue') !== false) {
            $infoType = self::TypeRevenue;
        } elseif (stripos($data[1], 'payout') !== false) {
            $infoType = self::TypePayout;
        }

        $this->data = Array(
            'date' => self::ParseDate($data[0]),
            'description' => $data[1],
            'debet' => self::ParseCurrency($data[2]),
            'credit' => self::ParseCurrency($data[3]),
            'balance' => self::ParseCurrency($data[4]),
            'infoType' => $infoType
        );
    }

    public function GetDate() {
        return $this->data['date'];
    }

    public function GetDescription() {
        return $this->data['description'];
    }

    public function GetDebet() {
        return $this->data['debet'];
    }

    public function GetCredit() {
        return $this->data['credit'];
    }

    public function GetBalance() {
        return $this->data['balance'];
    }

    public function GetInfoType() {
        return $this->data['infoType'];
    }
}

class InvoiceInfo extends ParsedData {
    use ConvertableObject;

    const StatusUnknown = -1;
    const StatusDownloaded = 1;
    const StatusNotDownloaded = 2;
    const StatusAnotherLicense = 3;
    const StatusRefunded = 4;
    const StatusChargedBack = 5;

    function __construct($data) {
        $status = self::StatusUnknown;

        // Parse status
        $statusString = $data[5];
        if (stripos($statusString, 'not downloaded') !== false) {
            $status = self::StatusNotDownloaded;
        } elseif (stripos($statusString, 'downloaded') !== false) {
            $status = self::StatusDownloaded;
        } elseif (stripos($statusString, 'license') !== false) {
            $status = self::StatusAnotherLicense;
        } elseif (stripos($statusString, 'refund') !== false) {
            $status = self::StatusRefunded;
        } elseif (stripos($statusString, 'charge') !== false) {
            $status = self::StatusChargedBack;
        }

        $this->data = Array(
            'invoiceNumber' => (int) $data[0],
            'packageName' => $data[1],
            'quantity' => (int) $data[2],
            'totalPrice' => self::ParseCurrency($data[3]),
            'date' => self::ParseDate($data[4]),
            'status' => $status,
        );
    }

    public function GetInvoiceNumber() {
        return $this->data['invoiceNumber'];
    }

    public function GetPackageName() {
        return $this->data['packageName'];
    }

    public function GetQuantity() {
        return $this->data['quantity'];
    }

    public function GetTotalPrice() {
        return $this->data['totalPrice'];
    }

    public function GetPurchaseDate() {
        return $this->data['date'];
    }

    public function GetStatus() {
        return $this->data['status'];
    }
}

class SalesPeriod {
    use ConvertableObject;

    private $year;
    private $month;

    function __construct($data) {
        $this->year = (int) substr($data->value, 0, 4);
        $this->month = (int) substr($data->value, 4, 2);
    }

    public function GetYear() {
        return $this->year;
    }

    public function GetMonth() {
        return $this->month;
    }

    public function GetDate() {
        return mktime(0, 0, 0, $this->month + 1, 0, $this->year);
    }
}

class PeriodSalesInfo {
    use ConvertableObject;

    private $packageSales;
    private $revenueGross;
    private $revenueNet;
    private $payoutCut;

    function __construct($packageSales, $payoutCut = 0.7) {
        $this->packageSales = $packageSales;
        $this->payoutCut = (float)$payoutCut;

        $this->revenueGross = 0;
        foreach ($this->packageSales as $value) {
            $this->revenueGross += $value->GetPrice() * ($value->GetQuantity() -
                                                         $value->GetRefunds() -
                                                         $value->GetChargebacks());
        }

        $this->revenueNet = $this->revenueGross * $this->payoutCut;
    }

    function GetPackageSales() {
        return $this->packageSales;
    }

    function GetRevenueGross() {
        return $this->revenueGross;
    }

    function GetRevenueNet() {
        return $this->revenueNet;
    }

    function GetPayoutCut() {
        return $this->payoutCut;
    }
}

class PeriodDownloadsInfo {
    use ConvertableObject;

    private $packageDownloads;

    function __construct($packageDownloads) {
        $this->packageDownloads = $packageDownloads;
    }

    function GetPackageDownloads() {
        return $this->packageDownloads;
    }
}

class PackageSalesInfo extends ParsedData {
    use ConvertableObject;

    function __construct($data) {
        $this->data = Array(
            'name' => $data[0],
            'price' => self::ParseCurrency($data[1]),
            'quantity' => $data[2] != null ? (int) $data[2] : null,
            'refunds' => $data[3] != null ? abs((int) $data[3]) : null,
            'chargebacks' => $data[4] != null ? abs((int) $data[4]) : null,
            'gross' => $data[5] != null ? self::ParseCurrency($data[5]) : null,
            'firstPurchase' => $data[6] != null ? self::ParseDate($data[6]) : null,
            'lastPurchase' => $data[7] != null ? self::ParseDate($data[7]) : null,
            'shortUrl' => $data['shortUrl'],
        );
    }

    public function GetPackageName() {
        return $this->data['name'];
    }

    public function GetPrice() {
        return $this->data['price'];
    }

    public function GetQuantity() {
        return $this->data['quantity'];
    }

    public function GetRefunds() {
        return $this->data['refunds'];
    }

    public function GetChargebacks() {
        return $this->data['chargebacks'];
    }

    public function GetGross() {
        return $this->data['gross'];
    }

    public function GetFirstPurchaseDate() {
        return $this->data['firstPurchase'];
    }

    public function GetLastPurchaseDate() {
        return $this->data['lastPurchase'];
    }

    public function GetShortUrl() {
        return $this->data['shortUrl'];
    }

    public function FetchPackageId() {
        $redirect = HttpUtilities::GetRedirectUrl($this->data['shortUrl']);
        $redirect = end(explode('/', $redirect));

        return $redirect;
    }
}

class PackageDownloadsInfo extends ParsedData {
    use ConvertableObject;

    function __construct($data) {
        $this->data = Array(
            'name' => $data[0],
            'quantity' => $data[1] != null ? (int) $data[1] : null,
            'firstDownload' => $data[2] != null ? self::ParseDate($data[2]) : null,
            'lastDownload' => $data[3] != null ? self::ParseDate($data[3]) : null,
            'shortUrl' => $data['shortUrl'],
        );
    }

    public function GetPackageName() {
        return $this->data['name'];
    }

    public function GetQuantity() {
        return $this->data['quantity'];
    }

    public function GetFirstDownloadDate() {
        return $this->data['firstDownload'];
    }

    public function GetLastDownloadDate() {
        return $this->data['lastDownload'];
    }

    public function GetShortUrl() {
        return $this->data['shortUrl'];
    }

    public function FetchPackageId() {
        $redirect = HttpUtilities::GetRedirectUrl($this->data['shortUrl']);
        $redirect = end(explode('/', $redirect));

        return $redirect;
    }
}

class PackageVersionInfo extends ParsedData {
    use ConvertableObject;

    const StatusUnknown = -1;
    const StatusError = 1;
    const StatusDraft = 2;
    const StatusPending = 3;
    const StatusDeclined = 4;
    const StatusPublished = 5;

    function __construct($data) {
        $status = self::StatusUnknown;

        // Parse status
        if (stripos($data->status, 'published') !== false) {
            $status = self::StatusPublished;
        } elseif (stripos($data->status, 'pending') !== false) {
            $status = self::StatusPending;
        } elseif (stripos($data->status, 'declined') !== false) {
            $status = self::StatusDeclined;
        } elseif (stripos($data->status, 'draft') !== false) {
            $status = self::StatusDraft;
        } elseif (stripos($data->status, 'error') !== false) {
            $status = self::StatusError;
        }

        $this->data = Array(
            'name' => $data->name,
            'status' => $status,
            'size' => (int) $data->size,
            'modifiedDate' => self::ParseDateTime($data->modified),
            'createdDate' => self::ParseDateTime($data->created),
            'publishedDate' => self::ParseDateTime($data->published),
            'price' => (float) $data->price,
            'version' => $data->version_name,
            'categoryId' => (int) $data->category_id,
            'releaseNotes' => $data->publishnotes,
        );
    }

    public function GetName() {
        return $this->data['name'];
    }

    public function GetStatus() {
        return $this->data['status'];
    }

    public function GetVersion() {
        return $this->data['version'];
    }

    public function GetSize() {
        return $this->data['size'];
    }

    public function GetPrice() {
        return $this->data['price'];
    }

    public function GetCategoryId() {
        return $this->data['categoryId'];
    }

    public function GetReleaseNotes() {
        return $this->data['releaseNotes'];
    }

    public function GetModifiedDate() {
        return $this->data['modifiedDate'];
    }

    public function GetCreatedDate() {
        return $this->data['createdDate'];
    }

    public function GetPublishedDate() {
        return $this->data['publishedDate'];
    }
}

class PackageInfo extends ParsedData {
    function __construct($data) {
        $versions = Array();
        foreach ($data->versions as $versionData) {
            $versions[] = new PackageVersionInfo($versionData);
        }

        $this->data = Array(
            'id' => (int) $data->id,
            'shortUrl' => $data->short_url,
            'versions' => $versions
        );
    }

    public function GetId() {
        return $this->data['id'];
    }

    public function GetShortUrl() {
        return $this->data['shortUrl'];
    }

    public function GetVersions() {
        return $this->data['versions'];
    }
}

class HttpUtilities {
    private static $errorMessages = Array(
        // 1xx Info / Informational
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        // 2xx Success / OK',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        // 3xx Redirect',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        //4xx Client Error',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        // 5xx Server Error',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required'
    );

    public static function IsErrorCode($code) {
        // Error codes begin at 400
        return is_numeric($code) && $code >= 400;
    }

    public static function GetStatusMessage($code) {
        return self::$errorMessages[$code];
    }

    public static function IsKnownCode($code) {
        return array_key_exists($code, self::$errorMessages);
    }

    // http://w-shadow.com/blog/2008/07/05/how-to-get-redirect-url-in-php/
    public static function GetRedirectUrl($url) {
        $redirect_url = null;

        $url_parts = @parse_url($url);
        if (!$url_parts) return false;
        if (!isset($url_parts['host'])) return false; //can't process relative URLs
        if (!isset($url_parts['path'])) $url_parts['path'] = '/';

        $sock = fsockopen($url_parts['host'], (isset($url_parts['port']) ? (int)$url_parts['port'] : 80), $errno, $errstr, 30);
        if (!$sock) return false;

        $request = "HEAD " . $url_parts['path'] . (isset($url_parts['query']) ? '?'.$url_parts['query'] : '') . " HTTP/1.1\r\n";
        $request .= 'Host: ' . $url_parts['host'] . "\r\n";
        $request .= "Connection: Close\r\n\r\n";
        fwrite($sock, $request);
        $response = '';
        while(!feof($sock)) $response .= fread($sock, 8192);
        fclose($sock);

        if (preg_match('/^Location: (.+?)$/m', $response, $matches)) {
            if (substr($matches[1], 0, 1) == "/")
                return $url_parts['scheme'] . "://" . $url_parts['host'] . trim($matches[1]);
            else
                return trim($matches[1]);

        } else {
            return false;
        }
    }
}

?>