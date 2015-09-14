<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Unity Publisher API for PHP demo</title>
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

    .changelog_cell{
        position: relative;
    }

    .changelog_cell:hover .changelog_body {
        display: block;
    }

    .changelog_body {
        display: none;
        top: 0;
        right: 100%;
        width: 500px;
        position: absolute;
        padding: 5px;
        background: white;
        border: 2px solid gray;
        z-index: 1000; 
        overflow: auto;
    }
</style>
</head>
<body>
<?php
    require 'AssetStorePublisherClient.class.php'; // Include the class

    // Create the client instance
    $store = new AssetStore\Client();

    // Login with your credentials
    $store->Login('your@publisher.email.com', 'password');

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

<h2>Sales and downloads</h2>
Period: <select name="selectedPeriod" onChange="document.forms['asForm'].submit();">
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
<h3>Sales</h3>
<?php
    $salesYear = reset($salesPeriods)->GetYear();
    $salesMonth = reset($salesPeriods)->GetMonth();

    if (isset($_POST['selectedPeriod'])) {
        $periodArray = explode('-', $_POST['selectedPeriod']);
        $salesYear = $periodArray[0];
        $salesMonth = $periodArray[1];
    }

    $sales = $store->FetchSales($salesYear, $salesMonth);
    $downloads = $store->FetchDownloads($salesYear, $salesMonth);
?>

<h4>Gross: $<?php echo $sales->GetRevenueGross(); ?>, net: $<?php echo $sales->GetRevenueNet(); ?> (<?php echo $sales->GetPayoutCut() * 100; ?>%)</h4>
<table>
<tr><th>Package name</th><th>Price ($)</th><th>Qty</th><th>Refunds</th><th>Chargebacks</th><th>Gross ($)</th><th>First</th><th>Last</th></tr>
<?php
    foreach ($sales->GetPackageSales() as $value) {
        echo sprintf('<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', 
                     $value->GetShortUrl(),
                     $value->GetPackageName(),
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

<h3>Free Downloads</h3>
<table>
<tr><th>Package name</th><th>Qty</th><th>First</th><th>Last</th></tr>
<?php
    foreach ($downloads->GetPackageDownloads() as $value) {
        echo sprintf('<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>', 
                     $value->GetShortUrl(),
                     $value->GetPackageName(),
                     $value->GetQuantity(),
                     $value->GetFirstDownloadDate() == null ? null : date('d F Y', $value->GetFirstDownloadDate()),
                     $value->GetLastDownloadDate() == null ? null : date('d F Y', $value->GetLastDownloadDate())
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

<h2>Packages</h2>
<table>
<tr><th>Package</th><th>Status</th><th>Version</th><th>Price ($)</th><th>Size</th><th>Created</th><th>Published</th><th>Modified</th><th>Id</th><th>Changelog</th></tr>
<?php
    $packages = $store->FetchPackages();

    foreach ($packages as $package) {
        $versions = $package->GetVersions();

        echo "<tr>";

        foreach ($versions as $version) {
            switch ($version->GetStatus()) {
                case AssetStore\PackageVersionInfo::StatusPublished:
                    $infoType = 'Published';
                    break;
                case AssetStore\PackageVersionInfo::StatusDraft:
                    $infoType = 'Draft';
                    break;
                case AssetStore\PackageVersionInfo::StatusPending:
                    $infoType = 'Pending';
                    break;
                case AssetStore\PackageVersionInfo::StatusDeclined:
                    $infoType = 'Declined';
                    break;
                case AssetStore\PackageVersionInfo::StatusError:
                    $infoType = 'Error';
                    break;
                default:
                    $infoType = 'Unknown';
                    break;
            }

            $size = $version->GetSize();

            echo sprintf('<td><a href="%s">%s</a></td>
                          <td>%s</td>
                          <td>%s</td>
                          <td>%s</td>
                          <td>%s kB</td>
                          <td>%s</td>
                          <td>%s</td>
                          <td>%s</td>
                          <td>%s</td>
                          <td class="changelog_cell"><i>Hover to see</i><div class="changelog_body">%s</div></td>', 
                         $package->GetShortUrl(), 
                         $version->GetName(), 
                         $infoType,
                         $version->GetVersion(),
                         $version->GetPrice(),
                         $size / 1000,
                         date('d F Y H:i:s', $version->GetCreatedDate()),
                         date('d F Y H:i:s', $version->GetPublishedDate()),
                         date('d F Y H:i:s', $version->GetModifiedDate()),
                         $package->GetId(),
                         htmlspecialchars($version->GetReleaseNotes())
                         );
        }

        echo "</tr>";
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
                         $value->GetPackageName(),
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