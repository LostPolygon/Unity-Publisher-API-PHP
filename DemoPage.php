<html>
<head>
<title>UnityAssetStorePublisherPHP demo</title>
<style>
    table {
        border-collapse: collapse;
    }
    td, th {
        border: 1px gray solid;
        padding: 5px;
    }
    th {
        background: #EEE;
    }
</style>
</head>
<body>
<?php
    require 'AssetStorePublisherClient.class.php'; // Include the class

    // Create the client instance
    $store = new AssetStore\Client();

    // Login with your credentials
    $store->Login('your@email.com', 'password');

    // Or, if you don't want to keep your credentials in the code, use the token (returned by Login() method or retrieved from your browser cookies)
    // $store->LoginWithToken('put your token here');
?>

<form method="post" id="asForm">
<h2>Publisher info</h2>
<ul>
<?php
    $publisherInfo = $store->GetPublisherInfo();
?>
<li>Id: <?php echo $publisherInfo->GetId(); ?></li>
<li>Name: <?php echo $publisherInfo->GetName(); ?></li>
<li>Description: <?php echo $publisherInfo->GetDescription(); ?></li>
<li>Rating: <?php echo $publisherInfo->GetRating(); ?> (from <?php echo $publisherInfo->GetRatingCount(); ?> votes)</li>
<li>Payout cut: <?php echo $publisherInfo->GetPayoutCut() * 100; ?>%</li>
<li>Publisher URL: <?php echo $publisherInfo->GetPublisherUrl(); ?></li>
<li>Publisher URL (short): <?php echo $publisherInfo->GetPublisherShortUrl(); ?></li>
<li>URL: <?php echo $publisherInfo->GetSiteUrl(); ?></li>
<li>Support URL: <?php echo $publisherInfo->GetSupportUrl(); ?></li>
<li>Support email: <?php echo $publisherInfo->GetSupportEmail(); ?></li>
</ul>

<h2>Sales periods</h2>
<ul>
<?php
    $salesPeriods = $store->FetchSalesPeriods();
    foreach ($salesPeriods as $value) {
        echo sprintf('<li>Month: %d, year: %d, formatted: %s</li>', 
                     $value->GetMonth(), 
                     $value->GetYear(),
                     date('F Y', $value->GetDate()));
    }
?>
</ul>

<h2>Sales</h2>
<?php
    $salesYear = reset($salesPeriods)->GetYear();
    $salesMonth = reset($salesPeriods)->GetMonth();

    if (isset($_POST['selectedPeriod'])) {
        $periodArray = explode('-', $_POST['selectedPeriod']);
        $salesYear = $periodArray[0];
        $salesMonth = $periodArray[1];
    }

    $sales = $store->FetchSales($salesYear, $salesMonth);
?>

<h3>Gross: $<?php echo $sales->GetRevenueGross(); ?>, net: $<?php echo $sales->GetRevenueNet(); ?> (<?php echo $sales->GetPayoutCut() * 100; ?>%)</h3>
<select name="selectedPeriod" onChange="document.forms['asForm'].submit();">
<?php
    foreach ($salesPeriods as $value) {
        echo sprintf('<option value="%s" %s>%s</option>', 
                     $value->GetYear() . '-' . $value->GetMonth(),
                     ($value->GetYear() == $salesYear && $value->GetMonth() == $salesMonth) ? 'selected' : '',
                     date('F Y', $value->GetDate())
                     );
    }
?>
</select>
<br>
<br>
<table>
<tr><th>Package</th><th>Price ($)</th><th>Qty</th><th>Refunds</th><th>Chargebacks</th><th>Gross ($)</th><th>First</th><th>Last</th></tr>
<?php
    foreach ($sales->GetAssetSales() as $value) {
        echo sprintf('<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', 
                     $value->GetShortUrl(),
                     $value->GetAssetName(),
                     $value->GetPrice(),
                     $value->GetQuantity(),
                     $value->GetRefunds(),
                     $value->GetChargebacks(),
                     $value->GetGross() == 0 ? null : $value->GetGross(),
                     $value->GetFirstPurchaseDate() == null ? null : date('d F Y', $value->GetFirstPurchaseDate()),
                     $value->GetLastPurchaseDate() == null ? null : date('d F Y', $value->GetLastPurchaseDate())
                     );
    }
?>
</table>

<h2>Revenue</h2>
<table>
<tr><th>Date</th><th>Type</th><th>Description</th><th>Debet ($)</th><th>Credit ($)</th><th>Balance ($)</th></tr>
<?php
    $revenue = $store->FetchRevenue();

    foreach ($revenue as $value) {
        switch ($value->GetInfoType()) {
            case AssetStore\RevenueInfo::TypeRevenue:
                $infoType = 'Revenue';
                break;
            case AssetStore\RevenueInfo::TypePayout:
                $infoType = 'Payout';
                break;
            default:
                $infoType = 'Unknown';
                break;
        }
        echo sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', 
                     date('d F Y', $value->GetDate()), 
                     $infoType,
                     $value->GetDescription(),
                     $value->GetDebet(),
                     $value->GetCredit(),
                     $value->GetBalance() == 0 ? null : $value->GetBalance()
                     );
    }
?>
</table>

<h2>Pending</h2>
<table>
<tr><th>Package</th><th>Status</th><th>Size</th><th>Updated</th></tr>
<?php
    $pending = $store->FetchPending();

    foreach ($pending as $value) {
        switch ($value->GetStatus()) {
            case AssetStore\PendingInfo::StatusDraft:
                $infoType = 'Draft';
                break;
            case AssetStore\PendingInfo::StatusPending:
                $infoType = 'Pending';
                break;
            case AssetStore\PendingInfo::StatusDeclined:
                $infoType = 'Declined';
                break;
            case AssetStore\PendingInfo::StatusError:
                $infoType = 'Error';
                break;
            default:
                $infoType = 'Unknown';
                break;
        }

        $size = $value->GetPackageSize();

        echo sprintf('<tr><td>%s</td><td>%s</td><td>%s kB</td><td>%s</td></tr>', 
                     $value->GetAssetName(), 
                     $infoType,
                     $size / 1000,
                     date('d F Y', $value->GetUpdateDate())
                     );
    }
?>
</table>

<h2>Verify invoice</h2>
<?php
    $invoiceNumbers = '';

    if (isset($_POST['invoiceNumbers']) && !empty($_POST['invoiceNumbers'])) {
        $invoiceNumbers = $_POST['invoiceNumbers'];
        $invoiceNumbersArray = explode(',', $invoiceNumbers);

        $invoiceNumbersInfo = $store->VerifyInvoice($invoiceNumbersArray);
    }
?>
Enter comma separated invoice numbers: 
<input type="text" name="invoiceNumbers" value="<?php echo $invoiceNumbers; ?>" size="13">
<button onclick="document.forms['asForm'].submit();">Verify</button>
<br>
<br>
<table>
<tr><th>Invoice #</th><th>Package</th><th>Purchase</th><th>Refunded?</th></tr>
<?php
    if (isset($invoiceNumbersInfo)) {
        foreach ($invoiceNumbersInfo as $value) {
            echo sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', 
                         $value->GetInvoiceNumber(),
                         $value->GetAssetName(),
                         date('d F Y', $value->GetPurchaseDate()),
                         $value->IsRefunded() ? 'Yes' : 'No'
                         );
        }
    }
?>
</table>

</form>
</body>
</html>