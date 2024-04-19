 <?php
use Dialeleven\PhpGoogleXmlSitemap;


include_once $_SERVER['DOCUMENT_ROOT'] . '/src/GoogleNewsSitemap.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/public/db_connect.inc.php';


/*
Instansiate the PHP Google XML Sitemap Class. Pass your hostname below as an 
argument using PHP's $_SERVER['HTTP_HOST'] or you can hard code your hostname
such as 'https://www.yourdomain.com' for example.

*** DO NOT INCLUDE A TRAILING SLASH AT THE END OF YOUR HOSTNAME! ***
*/

#$my_sitemap = new Dialeleven\PhpGoogleXmlSitemap\GoogleXmlSitemap($http_hostname = $_SERVER['HTTP_HOST']);
$my_sitemap = new Dialeleven\PhpGoogleXmlSitemap\GoogleXmlSitemap($sitemap_type = 'news', $http_hostname = 'www.testdomain.com', $xml_files_dir = $_SERVER['DOCUMENT_ROOT'] . '/public/sitemaps');



/*
Some configuratation methods for your sitemap file(s) to be generated.
*/
#$my_sitemap->setXmlMode($mode = 'file'); // mode = memory (browser), mode = file (save to XML file)

$my_sitemap->setUseHttpsUrls(true); // use "https" mode for your URLs or plain "http"
$my_sitemap->setSitemapFilenamePrefix('mysitemap'); // set name of sitemap file minus ".xml" (e.g. mysitemap.xml)
$my_sitemap->setUseGzip($use_gzip = false); // gzip the urlset files to reduce file sizes (true/false)



/*
Start adding your URLs
*/
$sql = 'SELECT url FROM sample ORDER BY url';
// mysql PDO query non-prepared statement
$stmt = $pdo->query($sql);

while ($query_data = $stmt->fetch())
{
   echo $query_data->url . '<br>';

   /*
   // Add URLs from your database or array (if preferred)
   // 1. $loc - Should not include the hostname. For example if the URL is https://www.yourdomain.com/somepath/, then
   //           the $loc should be "somepath/" if you want the trailing slash. Trailing slash is not enforced for
   //           flexibility as some sites may not use a trailing slash.
   // 2. $tags_arr - here pass an array of optional URL tags including the following (which can be left out in my experience):
   //                   - lastmod (W3C Datetime format - can omit time and use YYYY-MM-DD)
                        - changefreq
                              always
                              hourly
                              daily
                              weekly
                              monthly
                              yearly
                              never
                        - priority (valid values 0.0 to 1.0 - default priority of a page is 0.5)

   // The class will create a new 'urlset' file if you reach the 50,000 URL limit and create
   // the 'sitemapindex' file listing each urlset file that was generated.
   */
   $my_sitemap->addUrl(
                          $loc = "$query_data->url/",
                          $tags_arr = array('lastmod' => '2024-04-19', 'changefreq' => 'weekly', 'priority' => '0.5')
                      );
}

// signal when done adding URLs, so we can generate the sitemap index file (table of contents)
$my_sitemap->endXmlDoc();



#throw new Exception('Test exception here');
#throw new InvalidArgumentException('test');