<?php
namespace AssetStore;

class AssetStoreException extends \Exception { }

class Client {
    const LOGIN_URL = 'https://publisher.assetstore.unity3d.com/login';
    const LOGOUT_URL = 'https://publisher.assetstore.unity3d.com/logout';
    const SALES_URL = 'https://publisher.assetstore.unity3d.com/sales.html';
    const USER_OVERVIEW_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/user/overview.json';
    const PUBLISHER_OVERVIEW_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher/overview.json';
    const SALES_PERIODS_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/months/{publisher_id}.json';
    const SALES_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/sales/{publisher_id}/{year}{month}.json';
    const DOWNLOADS_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/downloads/{publisher_id}/{year}{month}.json';
    const INVOICE_VERIFY_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/verify-invoice/{publisher_id}/{invoice_id}.json';
    const REVENUE_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/revenue/{publisher_id}.json';
    const PACKAGES_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/management/packages.json';
    const API_KEY_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/api-key/{publisher_id}.json';
    const LOGIN_TOKEN = '26c4202eb475d02864b40827dfff11a14657aa41';
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 6.3; rv:27.0) Gecko/20100101 Firefox/27.0';

    private $loginToken;
    private $isLoggedIn = false;
    private $cookies = Array();
    private $userInfoOverview = null;
    private $publisherInfoOverview = null;

    public function LoginWithToken($token) {
        $this->AssertIsNotLoggedIn();

        $this->loginToken = $token;
        $this->isLoggedIn = true;
        $this->cookies['xunitysession'] = $this->GetXUnitySessionCookie();
    }

    public function Login($user, $password) {
        $this->AssertIsNotLoggedIn();

        $token = $this->GetLoginToken($user, $password);
        $this->LoginWithToken($token);

        return $token;
    }

    public function Logout() {
        $this->AssertIsLoggedIn();

        $result = $this->GetSimpleData(Array('url' => self::LOGOUT_URL));
        self::AssertHttpCode('Logout failed, error code {code}', $result['http_code']);

        unset($this->cookies['xunitysession']);
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
            $result = $this->GetSimpleData(Array('url' => self::USER_OVERVIEW_JSON_URL));
            self::AssertHttpCode('Fetching user data failed, error code {code}', $result['http_code']);
    
            $this->userInfoOverview = json_decode($result['data']); 
        }

        return $this->userInfoOverview;
    }

    public function GetPublisherInfo() {
        $this->AssertIsLoggedIn();

        if ($this->publisherInfoOverview === null) {
            $result = $this->GetSimpleData(Array('url' => self::PUBLISHER_OVERVIEW_JSON_URL));
            self::AssertHttpCode('Fetching publisher data failed, error code {code}', $result['http_code']);
    
            $publisherInfoObject = json_decode($result['data']);
            $this->publisherInfoOverview = new PublisherInfo($publisherInfoObject);
        }

        return $this->publisherInfoOverview;
    }

    public function FetchApiKey() {
        $url = str_replace('{publisher_id}', $this->GetPublisherInfo()->GetId(), self::API_KEY_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url));
        self::AssertHttpCode('Fetching API key failed, error code {code}', $result['http_code']);

        $keyDataObject = json_decode($result['data']);
        return $keyDataObject->api_key;
    }

    public function FetchSalesPeriods() {
        $this->AssertIsLoggedIn();

        $url = str_replace('{publisher_id}', $this->GetPublisherInfo()->GetId(), self::SALES_PERIODS_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url));
        self::AssertHttpCode('Fetching sales periods failed, error code {code}', $result['http_code']);

        $salesPeriods = json_decode($result['data']);

        $infoArray = Array();
        foreach ($salesPeriods->periods as $value) {
            $infoArray[] = new SalesPeriod($value);
        }

        return $infoArray;
    }

    public function FetchRevenue() {
        $this->AssertIsLoggedIn();

        $url = str_replace('{publisher_id}', $this->GetPublisherInfo()->GetId(), self::REVENUE_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url));
        self::AssertHttpCode('Fetching sales periods failed, error code {code}', $result['http_code']);

        $infoObject = json_decode($result['data']);

        $infoArray = Array();
        foreach ($infoObject->aaData as $value) {
            $infoArray[] = new RevenueInfo($value);
        }

        return $infoArray;
    }

    public function FetchPackages() {
        $this->AssertIsLoggedIn();

        $url = str_replace('{publisher_id}', $this->GetPublisherInfo()->GetId(), self::PACKAGES_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url));
        self::AssertHttpCode('Fetching packages failed, error code {code}', $result['http_code']);

        $infoObject = json_decode($result['data']);

        $infoArray = Array();
        foreach ($infoObject->packages as $value) {
            $infoArray[] = new PackageInfo($value);
        }

        return $infoArray;
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
        $url = str_replace(Array('{publisher_id}', '{invoice_id}'),
                           Array($this->GetPublisherInfo()->GetId(), urlencode($invoiceNumbers)), 
                           self::INVOICE_VERIFY_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url));
        self::AssertHttpCode('Invoice verification failed, error code {code}', $result['http_code']);

        $invoiceInfoObject = json_decode($result['data']);
        $invoiceInfo = Array();
        foreach ($invoiceInfoObject->aaData as $value) {
            $invoiceInfo[] = new InvoiceInfo($value);
        }

        return $invoiceInfo;
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
        $url = str_replace(Array('{publisher_id}', '{year}', '{month}'), 
                           Array($this->GetPublisherInfo()->GetId(), $year, $month), 
                           self::SALES_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url));
        self::AssertHttpCode('Fetching sales failed, error code {code}', $result['http_code']);

        $salesInfoObject = json_decode($result['data']);
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
        $url = str_replace(Array('{publisher_id}', '{year}', '{month}'), 
                           Array($this->GetPublisherInfo()->GetId(), $year, $month), 
                           self::DOWNLOADS_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url));
        self::AssertHttpCode('Fetching downloads failed, error code {code}', $result['http_code']);

        $downloadsInfoObject = json_decode($result['data']);
        $downloadsInfo = Array();

        foreach ($downloadsInfoObject->aaData as $key => $value) {
            $value['shortUrl'] = $downloadsInfoObject->result[$key]->short_url;
            $downloadsInfo[] = new PackageDownloadsInfo($value);
        }

        return new PeriodDownloadsInfo($downloadsInfo);
    }

    private function SetupCurlQuery($params) {
        if (isset($params['query'])) {
            $query = http_build_query($params['query']);
        }

        $cookies = http_build_query($this->cookies, '', '; ');
        $headers = Array();
        if (isset($params['headers'])) {
            $headers = array_merge($headers, $params['headers']);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_URL, $params['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        if (isset($params['query'])) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_REFERER, isset($params['referer']) ? $params['referer'] : $params['url']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  

        return $ch;
    }

    private function GetSimpleData($params) {
        $result = Array();

        $ch = $this->SetupCurlQuery($params); 
        $result['data'] = curl_exec($ch);
        $curlError = curl_error($ch);
        $result['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!empty($curlError)) {
            throw new AssetStoreException("CURL error occured: {$curlError}");
        }

        return $result;
    }

    private function GetXUnitySessionCookie() {
        if ($this->isLoggedIn) {
            return $this->loginToken . self::LOGIN_TOKEN . self::LOGIN_TOKEN;
        } else {
            return self::LOGIN_TOKEN . self::LOGIN_TOKEN . self::LOGIN_TOKEN;
        }
    }

    private function GetLoginToken($user, $password) {
        $query = 
            Array(
                'user' => $user, 
                'pass' => $password,
                'skip_terms' => 'true'
            );

        $ch = self::SetupCurlQuery(Array('url' => self::LOGIN_URL, 
                                         'query' => $query,
                                         'headers' => Array('X-Unity-Session: ' . $this->GetXUnitySessionCookie()))); 
        $result_data = curl_exec($ch);
        $result_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::AssertHttpCode('Login failed, error code {code}', $result_http_code);

        return trim($result_data);
    }

    private static function AssertHttpCode($message, $code) {
        if (HttpUtilities::IsErrorCode($code)) {
            throw new AssetStoreException(str_replace('{code}', $code . ' (' . HttpUtilities::GetStatusMessage($code) . ')', $message));
        }
    }
  
    private function AssertIsLoggedIn() {
        if (!$this->IsLoggedIn()) {
            throw new AssetStoreException('Can\'t execute operation when not logged in');
        }
    }

    private function AssertIsNotLoggedIn() {
        if ($this->IsLoggedIn()) {
            throw new AssetStoreException('Login already performed');
        }
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
                            'publisherUrl' => $data->long_url,
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

    public function GetPublisherUrl() {
        return $this->data['publisherUrl'];
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

            //var_dump($data->modified);
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
        // [Informational 1xx]    
        100 => 'Continue',    
        101 => 'Switching Protocols',    
        // [Successful 2xx]    
        200 => 'OK',    
        201 => 'Created',    
        202 => 'Accepted',    
        203 => 'Non-Authoritative Information',    
        204 => 'No Content',    
        205 => 'Reset Content',    
        206 => 'Partial Content',    
        // [Redirection 3xx]    
        300 => 'Multiple Choices',    
        301 => 'Moved Permanently',    
        302 => 'Found',    
        303 => 'See Other',    
        304 => 'Not Modified',    
        305 => 'Use Proxy',    
        306 => '(Unused)',    
        307 => 'Temporary Redirect',    
        // [Client Error 4xx]    
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
        // [Server Error 5xx]    
        500 => 'Internal Server Error',    
        501 => 'Not Implemented',    
        502 => 'Bad Gateway',    
        503 => 'Service Unavailable',    
        504 => 'Gateway Timeout',    
        505 => 'HTTP Version Not Supported'
    );

    public static function IsErrorCode($code) {
        // Error codes begin at 400
        return is_numeric($code) && $code >= 400;
    }

    public static function GetStatusMessage($code) {
        return self::$errorMessages[$code];
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