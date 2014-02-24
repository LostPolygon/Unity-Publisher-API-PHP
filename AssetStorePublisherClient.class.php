<?php
namespace AssetStore;

class Client {
    const LOGIN_URL = 'https://publisher.assetstore.unity3d.com/login';
    const LOGOUT_URL = 'https://publisher.assetstore.unity3d.com/logout';
    const SALES_URL = 'https://publisher.assetstore.unity3d.com/sales.html';
    const USER_OVERVIEW_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/user/overview.json';
    const PUBLISHER_OVERVIEW_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher/overview.json';
    const SALES_PERIODS_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/sales-periods/{publisher_id}.json';
    const SALES_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/sales/{publisher_id}/{year}{month}.json';
    const INVOICE_VERIFY_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/verify-invoice/{publisher_id}/{invoice_id}.json';
    const REVENUE_JSON_URL = 'https://publisher.assetstore.unity3d.com/api/publisher-info/revenue/{publisher_id}.json';
    const LOGIN_TOKEN = '26c4202eb475d02864b40827dfff11a14657aa41';
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 6.3; rv:27.0) Gecko/20100101 Firefox/27.0';

    private $loginToken;
    private $isLoggedIn = false;
    private $cookies = Array();
    private $userInfoOverview = null;
    private $publisherInfoOverview = null;

    public function LoginWithToken($token) {
        $this->loginToken = $token;
        $this->isLoggedIn = true;
        $this->cookies['xunitysession'] = $this->GetXUnitySessionCookie();
    }

    public function Login($user, $password) {
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
    
            $publisherInfoObject = json_decode($result['data'])->overview;
            $this->publisherInfoOverview = new PublisherInfo(Array(
                                                                'id' => $publisherInfoObject->id,
                                                                'name' => $publisherInfoObject->name,
                                                                'description' => $publisherInfoObject->description,
                                                                'rating' => $publisherInfoObject->rating->average,
                                                                'ratingCount' => $publisherInfoObject->rating->count,
                                                                'payoutCut' => $publisherInfoObject->payout_cut,
                                                                'publisherUrl' => $publisherInfoObject->long_url,
                                                                'publisherShortUrl' => $publisherInfoObject->short_url,
                                                                'siteUrl' => $publisherInfoObject->url,
                                                                'supportUrl' => $publisherInfoObject->support_url,
                                                                'supportEmail' => $publisherInfoObject->support_email
                                                            ));
        }

        return $this->publisherInfoOverview;
    }

    public function FetchSalesPeriods() {
        $this->AssertIsLoggedIn();

        $url = str_replace('{publisher_id}', $this->GetPublisherInfo()->GetId(), self::SALES_PERIODS_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url));
        self::AssertHttpCode('Fetching sales periods failed, error code {code}', $result['http_code']);

        $salesPeriods = json_decode($result['data']);

        $infoArray = Array();
        foreach ($salesPeriods->periods as $value) {
            $year = substr($value->value, 0, 4);
            $month = substr($value->value, 4, 2);
            $infoArray[] = new SalesPeriod((int)$year, (int)$month);
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
            $infoObjectArray = Array(
                'date' => self::ParseDate($value[0]),
                'description' => $value[1],
                'debet' => self::ParseCurrency($value[2]),
                'credit' => self::ParseCurrency($value[3]),
                'balance' => self::ParseCurrency($value[4]),
            );

            $infoArray[] = new RevenueInfo($infoObjectArray);
        }

        return $infoArray;
    }

    public function VerifyInvoice($invoiceNumbers) {
        $this->AssertIsLoggedIn();

        if (!is_array($invoiceNumbers)) {
            $invoiceNumbers = Array($invoiceNumbers);
        }

        foreach ($invoiceNumbers as &$value) {
            $value = preg_replace('#[^0-9]#', '', $value);
        }
        unset($value);

        $invoiceNumbers = implode(' ', $invoiceNumbers);
  
        $url = str_replace(Array('{publisher_id}', '{invoice_id}'), 
                           Array($this->GetPublisherInfo()->GetId(), urlencode($invoiceNumbers)), 
                           self::INVOICE_VERIFY_JSON_URL);
        $result = $this->GetSimpleData(Array('url' => $url));
        self::AssertHttpCode('Invoice verification failed, error code {code}', $result['http_code']);

        $invoiceInfoObject = json_decode($result['data']);

        $invoiceInfo = Array();
        foreach ($invoiceInfoObject->aaData as $value) {
            $invoiceInfoArray = Array(
                'id' => $value[0],
                'assetName' => $value[1],
                'date' => self::ParseDate($value[2]),
                'isRefunded' => $value[3] !== 'No',
            );

            $invoiceInfo[] = new InvoiceInfo($invoiceInfoArray);
        }

        return $invoiceInfo;
    }

    public function FetchSales($year, $month) {
        $this->AssertIsLoggedIn();

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
            $salesInfoArray = Array(
                'name' => $value[0],
                'price' => self::ParseCurrency($value[1]),
                'quantity' => $value[2] != null ? (int) $value[2] : null,
                'refunds' => $value[3] != null ? abs((int) $value[3]) : null,
                'chargebacks' => $value[4] != null ? abs((int) $value[4]) : null,
                'gross' => $value[5] != null ? self::ParseCurrency($value[5]) : null,
                'firstPurchase' => $value[6] != null ? self::ParseDate($value[6]) : null,
                'lastPurchase' => $value[7] != null ? self::ParseDate($value[7]) : null,
                'shortLink' => $salesInfoObject->result[$key]->short_url,
            );

            $salesInfo[] = new AssetSalesInfo($salesInfoArray);
        }

        return new PeriodSalesInfo($salesInfo, $this->GetPublisherInfo()->GetPayoutCut());
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
        $result['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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

    private static function AssertHttpCode($message, $code, $expectedCode = 200) {
        if ($code != $expectedCode) {
            throw new AssetStoreException(str_replace('{code}', $code, $message));
        }
    }

    private function AssertIsLoggedIn() {
        if (!$this->isLoggedIn) {
            throw new AssetStoreException('Can\'t execute operation when not logged in');
        }
    }

    private static function ParseDate($date) {
        if(empty($date))
            return null;

        return \DateTime::createFromFormat('Y-m-d', $date)->getTimestamp();
    }

    private static function ParseCurrency($value) {
        if(empty($value))
            return null;

        return (float) preg_replace('#[^0-9,.-]#', '', $value);
    }
}

class AssetStoreException extends \Exception { }

class ParsedData {
    protected $data;
    function __construct($data) {
        $this->data = $data;
    }
}

class PublisherInfo extends ParsedData {
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
}

class InvoiceInfo extends ParsedData {
    public function GetInvoiceNumber() {
        return $this->data['id'];
    }

    public function GetAssetName() {
        return $this->data['assetName'];
    }

    public function GetPurchaseDate() {
        return $this->data['date'];
    }

    public function IsRefunded() {
        return $this->data['isRefunded'];
    }
}

class SalesPeriod {
    private $year;
    private $month;

    function __construct($year, $month) {
        $this->year = (int) $year;
        $this->month = (int) $month;
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
    private $assetSales;
    private $revenueGross;
    private $revenueNet;
    private $payoutCut;

    function __construct($assetSales, $payoutCut = 0.7) {
        $this->assetSales = $assetSales;
        $this->payoutCut = (float)$payoutCut;

        $this->revenueGross = 0;
        foreach ($this->assetSales as $value) {
            $this->revenueGross += $value->GetPrice() * ($value->GetQuantity() - 
                                                         $value->GetRefunds() - 
                                                         $value->GetChargebacks());
        }

        $this->revenueNet = $this->revenueGross * $this->payoutCut;
    }

    function GetAssetSales() {
        return $this->assetSales;
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

class AssetSalesInfo extends ParsedData {
    public function GetAssetName() {
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

    public function GetShortLink() {
        return $this->data['shortLink'];
    }

    public function FetchAssetId() {
        $redirect = self::GetRedirectUrl($this->data['shortLink']);
        $redirect = end(explode('/', $redirect));

        return $redirect;
    }

    // http://w-shadow.com/blog/2008/07/05/how-to-get-redirect-url-in-php/
    static function GetRedirectUrl($url) {
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
     
        if (preg_match('/^Location: (.+?)$/m', $response, $matches)){
            if ( substr($matches[1], 0, 1) == "/" )
                return $url_parts['scheme'] . "://" . $url_parts['host'] . trim($matches[1]);
            else
                return trim($matches[1]);
      
        } else {
            return false;
        }
    }
}
?>