<?php
/*
Filename:         google_sitemap_template.class.php
Author:           Francis Tsao
Date Created:     08/01/2008
Purpose:          Creates a gzipped google sitemap xml file with a list of URLs specified
                  by the passed SQL.
History:          12/06/2011 - commented out <changefreq> tag as Google does not pay
                               attention to this according to N___ B_ B___ [ft]
*/


/**
 * GoogleSitemap - create Google Sitemap listing of all URLs
 *
 * History: 
 *
 * Sample usage
 * <code>
 * $mysitemap = new GoogleSitemap($sql_total, $http_host, $sitemap_filename_prefix, $sitemap_changefreq, $path_adj);
 * 
 * // repeat this call as many times as required if assembling a sitemap that needs 
 * // to execute several different SQL statements
 * $mysitemap->createSitemapFile($sql, $db_field_name_arr, $loc_url_template, $url_arr);
 
 * $mysitemap->buildSitemapContents();
 * $mysitemap->buildSitemapIndexContents();
 * </code>
 *
 * @author Francis Tsao
 */
class GoogleSitemap
{
   var $pdo;
   var $sql;
   var $http_host; // http hostname (minus the "http://" part - e.g. www.example.ca)
   var $sitemap_filename_prefix = 'sitemap_changeme'; // YOUR_FILENAME_PREFIX1.xml.gz, YOUR_FILENAME_PREFIX2.xml.gz, etc
                                                      // (e.g. if prefix is "sitemap_clients" then you will get a sitemap index
                                                      // file "sitemap_clients.xml, and sitemap files "sitemap_clients1.xml.gz")
   var $sitemap_changefreq = 'weekly'; // Google Sitemap <changefreq> value (always, hourly, daily, weekly, monthly, yearly, never)
   
   var $total_links;                   // total number of <loc> URL links
   var $max_sitemap_links = 50000;     // maximum is 50,000 URLs per file
   #var $max_sitemap_links = 10;     // maximum is 50,000
   //var $max_filesize = 10485760;       // 10MB maximum (unsupported feature currently)
   var $num_sitemaps = 0;              // total number of Sitemap files
   var $sitemap_index_contents;        // contents of Sitemap index file
   var $sitemap_contents;              // contents of sitemap (URLs)
   var $status_item;                   // list item status messages
   var $error_msg;
   var $path_adj;                      // file path adjustment to root directory (e.g. "../../")
   var $use_hostname_prefix;           // flag to use supplied $http_host value for $http_host/whatever/is/passed/
                                       // in <url> tag or only the DB field supplied value which should contain http://www.domain.com
   
   var $db_field_name_arr;
   var $loc_url_template;
   var $url_arr;

   var $createSitemapFileWithDelayedWriteOptionCounter = 0;

   /**
     * Constructor gets total number of URLs and sets up various settings like Sitemap
     * filename prefix, HTTP host to use in <loc>, <changefreq>, and file path adjustment
     *
     * @param  string $sql_total  SQL query for "total" (this must be an SQL field alias - e.g. COUNT(*) AS total)
     * @param  string $http_host  http hostname to use for URLs - e.g. www.example.com, www.example.ca
     * @param  string $sitemap_filename_prefix  filename prefix to use for Sitemap index and Sitemap files
     * @param  string $sitemap_changefreq  Sitemap <changefreq> value (always, hourly, daily, weekly, monthly, yearly, never)
     * @param  int $path_adj  number of steps up to the root directory from the CALLING script, not this one
     * @access public
     * @return void
     */
   function GoogleSitemap($sql_total, $http_host, $sitemap_filename_prefix, $sitemap_changefreq, $path_adj = '', $use_hostname_prefix = true)
   {
      global $pdo;

      $this->pdo = $pdo;

      if (intval($path_adj))
      {
         for ($i = 1; $i <= $path_adj; ++$i)
            $this->path_adj .= '../';
      }
      else if (ereg('[^0-9]', $path_adj))
      {
         die('ERROR: $path_adj parameter should be an integer value only. ' . $path_adj . ' was passed. Line ' . __LINE__ . ' in file ' . __FILE__);
      }

      #echo $sql_total;

      #echo interpolateSQL($pdo, $sql, $params = ['cat_name' => $cat_name, 'cat_description' => $cat_description, 'meta_title' => $meta_title, 'meta_description' => $meta_description, 'cat_id' => $cat_id]); // sql debugging
      $stmt = $this->pdo->prepare($sql_total);
      $stmt->execute([]);

      $query_data = $stmt->fetch();
      $this->total_links += $query_data->total;
      
      $this->http_host = $http_host;
      $this->sitemap_filename_prefix = $sitemap_filename_prefix;
      $this->sitemap_changefreq = $sitemap_changefreq;
      $this->use_hostname_prefix = $use_hostname_prefix;
   }
   
   
   /**
     * Manually set the $total_links var in cases where passing the SQL to calculate the
     * total number of <loc> URLs is not possible (e.g. with calculating the total number of populated categories)
     *
     * @param  string $sql_total  SQL query for "total" (this must be an SQL field alias - e.g. COUNT(*) AS total)
     * @param  string $http_host  http hostname to use for URLs - e.g. www.example.com, www.example.ca
     * @param  string $sitemap_filename_prefix  filename prefix to use for Sitemap index and Sitemap files
     * @param  string $sitemap_changefreq  Sitemap <changefreq> value (always, hourly, daily, weekly, monthly, yearly, never)
     * @param  int $path_adj  number of steps up to the root directory from the CALLING script, not this one
     * @access public
     * @return void
     */
   function setTotalLinks($total_links)
   {
      $this->total_links = $total_links;
   }
   
   
   /**
     * Builds contents of the sitemap index file (similar to a table of contents).
     * @access public
     * @return void
     */
   function buildSitemapIndexContents()
   {
      $this->sitemap_index_contents = '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n";
      $this->sitemap_index_contents .= '<sitemapindex xmlns="http://www.google.com/schemas/sitemap/0.84"' . "\r\n";
      $this->sitemap_index_contents .= 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\r\n";
      $this->sitemap_index_contents .= 'xsi:schemaLocation="http://www.google.com/schemas/sitemap/0.84' . "\r\n";
      $this->sitemap_index_contents .= 'http://www.google.com/schemas/sitemap/0.84/siteindex.xsd">' . "\r\n";

      $lastmod = date('Y-m-d\TH:i:s+00:00', time());

      for ($i = 1; $i <= $this->num_sitemaps; ++$i)
      {
         $this->sitemap_index_contents .= '   <sitemap>' . "\r\n";
         $this->sitemap_index_contents .= "      <loc>https://$this->http_host/$this->sitemap_filename_prefix{$i}.xml.gz</loc>\r\n";
         $this->sitemap_index_contents .= '      <lastmod>' . $lastmod . '</lastmod>' . "\r\n";
         $this->sitemap_index_contents .= '   </sitemap>' . "\r\n";
      }
      
      $this->sitemap_index_contents .= '</sitemapindex>';
   }



   /**
     * Builds contents of the sitemap index file (similar to a table of contents).
     * @access public
     * @return void
     */
   function buildSitemapIndexContentsUrlsOnly()
   {
      $lastmod = date('Y-m-d\TH:i:s+00:00', time());

      for ($i = 1; $i <= $this->num_sitemaps; ++$i)
      {
         $this->sitemap_index_contents .= '   <sitemap>' . "\r\n";
         $this->sitemap_index_contents .= "      <loc>https://$this->http_host/$this->sitemap_filename_prefix{$i}.xml.gz</loc>\r\n";
         $this->sitemap_index_contents .= '      <lastmod>' . $lastmod . '</lastmod>' . "\r\n";
         $this->sitemap_index_contents .= '   </sitemap>' . "\r\n";
      }
   }
   
   
   /**
     * Creates and writes required number of sitemap files.
     * @access public
     * @param  string $sql  SQL to build <loc> URLs from (the $sql var is "required" but you can pass an empty var if using $url_arr)
     * @param  array $db_field_name_arr  array of DB field name(s) to substitute in $loc_url_template
     * @param  string $loc_url_template  templated URL string to substitute the db_field_name_arr with.
     *                                   Make sure the substitue templated items have the SAME name 
     *                                   AND ORDER as the actual database table field names!!!
     *
     *                                   *** ENSURE you include the leading forward slash ***.
     *
     *                                   Example 1:
     *                                     db_field_name_arr -> array('city_name', 'oct_name', 'oct_id')
     *                                     loc_url_template  -> /online-[city_name]-coupons/category-[oct_name]-[oct_id]/
     *                                     
     *                                     Note how the db_field_name_arr has three (3) elements and the 
     *                                     loc_url_template also has three (3) templated strings enclosed
     *                                     in square brackets.
     * @param  array $url_arr  array of URLs (if you want to add more urls to the sitemap)
     * @return void
     */
   function createSitemapFile($sql, $db_field_name_arr, $loc_url_template, $url_arr = '')
   {
      $this->sql = $sql; // store this as we're calling buildSitemapContents() in a bit
      $this->db_field_name_arr = $db_field_name_arr;
      $this->loc_url_template = $loc_url_template;
      $this->url_arr = $url_arr;

      #print_r($this->db_field_name_arr);

      // if URL array (URL, changefreq) is passed, then adjust the total number of links per Sitemap.
      // Change it across all Sitemaps for simplicities sake.
      if (is_array($url_arr))
      {
         $total_urls = count($url_arr);
         $this->total_links += $total_urls; // increment total number of URL links
         $this->max_sitemap_links -= $total_urls;
      }
      
      // calculate SQL LIMIT clause offset
      $offset = ($this->num_sitemaps < 2)
              ? ($this->num_sitemaps * $this->max_sitemap_links) - (1  * $this->num_sitemaps) 
              : $offset + $this->max_sitemap_links;
      $sql_limit = "LIMIT $offset, " . $this->max_sitemap_links;
      
      // calculate number of sitemap files we need based on the max allowed number of links.
      // NOTE: This $num_sitemaps variable is ONLY for the current call to buildSitemapContents() and
      //       NOT a running total of the number of sitemaps we have.
      $num_sitemaps = ceil($this->total_links / $this->max_sitemap_links);
      
      // create X number of req'd sitemap files
      for ($i = 0; $i < $num_sitemaps; $i++)
      {
         $offset = ($i < 2) ? ($i * $this->max_sitemap_links) - (1  * $i) : $offset + $this->max_sitemap_links;
         
         $sql_limit = "LIMIT $offset, " . $this->max_sitemap_links;
         $sitemap_contents = $this->buildSitemapContents($sql_limit);
         
         // if SQL executed results in no records returned, then don't write a file
         // (e.g. SQL for open pages for Local Online is run, but there are no open page records)
         if (empty($sitemap_contents)) { continue; }
         
         $gz = gzopen("$this->path_adj$this->sitemap_filename_prefix" . ($this->num_sitemaps + 1) . '.xml.gz', 'w9');
         
         if ($bytes_written = gzwrite($gz, $sitemap_contents))
         {
            $this->status_item .= "<li>Wrote " . number_format($bytes_written) .
                                  " bytes to $this->path_adj$this->sitemap_filename_prefix" . ($this->num_sitemaps + 1) . '.xml.gz</li>';
            gzclose($gz);
         }

         // increment total number of sitemaps
         ++$this->num_sitemaps;
      }
   }


   /**
     * Creates and writes required number of sitemap files.
     * @access public
     * @param  string $sql  SQL to build <loc> URLs from (the $sql var is "required" but you can pass an empty var if using $url_arr)
     * @param  array $db_field_name_arr  array of DB field name(s) to substitute in $loc_url_template
     * @param  string $loc_url_template  templated URL string to substitute the db_field_name_arr with.
     *                                   Make sure the substitue templated items have the SAME name
     *                                   AND ORDER as the actual database table field names!!!
     *
     *                                   *** ENSURE you include the leading forward slash ***.
     *
     *                                   Example 1:
     *                                     db_field_name_arr -> array('city_name', 'oct_name', 'oct_id')
     *                                     loc_url_template  -> /online-[city_name]-coupons/category-[oct_name]-[oct_id]/
     *
     *                                     Note how the db_field_name_arr has three (3) elements and the
     *                                     loc_url_template also has three (3) templated strings enclosed
     *                                     in square brackets.
     * @param  array $url_arr  array of URLs (if you want to add more urls to the sitemap)
     * @return void
     */
   function createSitemapFileWithDelayedWriteOption($sql, $db_field_name_arr, $loc_url_template,
                                                    $url_arr = '', $build_sitemap_contents = true)
   {
      $this->createSitemapFileWithDelayedWriteOptionCounter++;
      $this->sql = $sql; // store this as we're calling buildSitemapContents() in a bit
      $this->db_field_name_arr = $db_field_name_arr;
      $this->loc_url_template = $loc_url_template;
      $this->url_arr = $url_arr;

      // get total links for current SQL call
      #echo interpolateSQL($pdo, $sql, $params = ['cat_name' => $cat_name, 'cat_description' => $cat_description, 'meta_title' => $meta_title, 'meta_description' => $meta_description, 'cat_id' => $cat_id]); // sql debugging
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute([]);

      $totalrows_for_current_call = $stmt->rowCount();


      echo $this->sql . " has [<b style='color: blue;'>$totalrows</b>] rows.<p>Call [$this->createSitemapFileWithDelayedWriteOptionCounter] for createSitemapFileWithDelayedWriteOption()</p>" . '<hr>';
      #print_r($this->db_field_name_arr);

      // if URL array (URL, changefreq) is passed, then adjust the total number of links per Sitemap.
      // Change it across all Sitemaps for simplicities sake.
      if (is_array($url_arr))
      {
         $total_urls = count($url_arr);
         $this->total_links += $total_urls; // increment total number of URL links
         $this->max_sitemap_links -= $total_urls;
      }

      echo "\$this->total_links: $this->total_links<br>";

      // calculate SQL LIMIT clause offset
      $offset = ($this->num_sitemaps < 2)
              ? ($this->num_sitemaps * $this->max_sitemap_links) - (1  * $this->num_sitemaps)
              : $offset + $this->max_sitemap_links;
      $sql_limit = "LIMIT $offset, " . $this->max_sitemap_links;

      // calculate number of sitemap files we need based on the max allowed number of links.
      // NOTE: This $num_sitemaps variable is ONLY for the current call to buildSitemapContents() and
      //       NOT a running total of the number of sitemaps we have.
      #$num_sitemaps = ceil($this->total_links / $this->max_sitemap_links);
      $num_sitemaps = ceil($this->total_links / $this->max_sitemap_links);





      if ($build_sitemap_contents)
      {
         echo "BUILD THE ENTIRE FILE NOW<BR>";
         // create X number of req'd sitemap files
         for ($i = 0; $i < $num_sitemaps; $i++)
         {
            $sitemap_contents = $this->getXmlUrlsetTagStart();
            $sitemap_contents .= $this->sitemap_contents; // get the previous call's URLs

            $offset = ($i < 2) ? ($i * $this->max_sitemap_links) - (1  * $i) : $offset + $this->max_sitemap_links;

            $sql_limit = "LIMIT $offset, " . $this->max_sitemap_links;
            $sitemap_contents .= $this->buildSitemapContentsUrlsOnly($sql_limit);

            // if SQL executed results in no records returned, then don't write a file
            // (e.g. SQL for open pages for Local Online is run, but there are no open page records)
            if (empty($sitemap_contents)) { continue; }

            $sitemap_contents .= $this->getXmlUrlsetTagEnd();

            $gz = gzopen("$this->path_adj$this->sitemap_filename_prefix" . ($this->num_sitemaps + 1) . '.xml.gz', 'w9');

            if ($bytes_written = gzwrite($gz, $sitemap_contents))
            {
               $this->status_item .= "<li>Wrote " . number_format($bytes_written) .
                                     " bytes to $this->path_adj$this->sitemap_filename_prefix" . ($this->num_sitemaps + 1) . '.xml.gz</li>';
               gzclose($gz);
            }

            // increment total number of sitemaps
            ++$this->num_sitemaps;
         }
      }
      else
      {
         echo "########## JUST COLLECT THE URLs FOR NOW for <span style='font: 14px Courier'>[$sql]</span><BR><br>";
// create X number of req'd sitemap files
         for ($i = 0; $i < $num_sitemaps; $i++)
         {
            #$this->getXmlUrlsetTagStart();

            $offset = ($i < 2) ? ($i * $this->max_sitemap_links) - (1  * $i) : $offset + $this->max_sitemap_links;

            $sql_limit = "LIMIT $offset, " . $this->max_sitemap_links;
            $this->sitemap_contents = $this->buildSitemapContentsUrlsOnly($sql_limit);



            echo "\$this->sitemap_contents: $this->sitemap_contents<br>";

            // if SQL executed results in no records returned, then don't write a file
            // (e.g. SQL for open pages for Local Online is run, but there are no open page records)
            if (empty($sitemap_contents)) { continue; }
/*
            $this->getXmlUrlsetTagEnd();

            $gz = gzopen("$this->path_adj$this->sitemap_filename_prefix" . ($this->num_sitemaps + 1) . '.xml.gz', 'w9');

            if ($bytes_written = gzwrite($gz, $sitemap_contents))
            {
               $this->status_item .= "<li>Wrote " . number_format($bytes_written) .
                                     " bytes to $this->path_adj$this->sitemap_filename_prefix" . ($this->num_sitemaps + 1) . '.xml.gz</li>';
               gzclose($gz);
            }
*/
            // increment total number of sitemaps
            ++$this->num_sitemaps;
         }
      }

      echo "<hr><hr>=== <pre>$this->sitemap_contents</pre> ===<hr><hr>";
   }

   
   /**
     * Writes the sitemap index file listing all of the individual sitemap files used.
     * @access public
     * @return void
     */
   function writeSitemapIndexFile()
   {
      $sitemap_index_filename = "{$this->sitemap_filename_prefix}.xml";
      
      // open file for writing, any exisint file content will be overwritten
      if ( !($fp = @fopen("$this->path_adj$sitemap_index_filename", 'w') ) )
      {
         $this->error_msg .= "<li>Could not open file $this->path_adj$sitemap_index_filename for writing</li>";
      }
      // write file contents and update last update date
      else
      {
         fwrite($fp, $this->sitemap_index_contents);
         fclose($fp);
         @chmod("../../$this->path_adj$sitemap_index_filename", 0755);
         $this->status_item .= "<li>Wrote <a href=\"../../$this->path_adj$sitemap_index_filename\">$sitemap_index_filename</a></li>";
      }
   }
   
   
   /**
     * Builds the contents of a single sitemap file.
     * @param  string $sql_limit  SQL LIMIT clause
     * @access public
     * @return string $sitemap_contents
     */
   function buildSitemapContents($sql_limit)
   {
      // start processing SQL if passed
      if ($this->sql)
      {
         // <loc> url template cannot be blank
         if (empty($this->loc_url_template))
            die("ERROR: \$this->loc_url_template cannot be empty. Line " . __LINE__ .
                ' in ' . __FILE__ . ' from function ' . __FUNCTION__);
         
         preg_match_all("/\[[^\[\]]+\]/", $this->loc_url_template, $matches);

         $loc_url_template_arr_size = count($matches[0]);
         $db_field_name_arr_size = count($this->db_field_name_arr);
         
         // start swapping data as long as array sizes match up
         if ($db_field_name_arr_size == $loc_url_template_arr_size)
         {
            $loc_url_template = $this->loc_url_template;
            
            // replace each [string] replacement with the appropriate db column.
            // *** IMPORTANT: USE ONLY $loc_url_template and NOT $this->loc_url_template as that will overwrite ***
            //     the template causing subsequent calls to the buildSitemapContents method to fail in cases
            //     where we have to split the Sitemap into several smaller files!
            foreach ($this->db_field_name_arr as $db_field_name)
               $loc_url_template = preg_replace("/\[[^\[\]]+\]/", "\$query_data->$db_field_name", $loc_url_template, 1);
         }
         // if array sizes don't match, then user has missed including some data
         else
         {
            die("ERROR: DB field name array and URL template array do not contain the same number of elements. \$db_field_name_arr_size: $db_field_name_arr_size, \$loc_url_template_arr_size: $loc_url_template_arr_size. Line " . __LINE__ . ' in file ' . __FILE__ . '.');
         }
         
         // assemble full SQL string
         $sql = "$this->sql $sql_limit";
         #echo interpolateSQL($pdo, $sql, $params = ['cat_name' => $cat_name, 'cat_description' => $cat_description, 'meta_title' => $meta_title, 'meta_description' => $meta_description, 'cat_id' => $cat_id]); // sql debugging
         $stmt = $this->pdo->prepare($sql);
         $stmt->execute([]);


         if ($stmt->rowCount() > 0)
         {
            // get opening <?xml> and <urlset> start tag
            $sitemap_contents = $this->getXmlUrlsetTagStart();

            // if url array is present, build the URL entries for them
            $sitemap_contents .= $this->getUrlArraySitemapUrlTags();

            while ($query_data = $stmt->fetch())
            {
               #$sitemap_contents .= "   <!-- $query_data->client_name - $query_data->page_name -->\r\n";
               $sitemap_contents .= "   <url>\r\n";

               // use supplied $http_host value or DB field template only
               $sitemap_contents .= ($this->use_hostname_prefix) ? "      <loc>https://$this->http_host" : "      <loc>";

               // evaluate the URL template to substitute the DB variables
               eval('$sitemap_contents .= "' . $loc_url_template . '";?>');

               $sitemap_contents .= "</loc>\r\n";
               //$sitemap_contents .= "      <changefreq>$this->sitemap_changefreq</changefreq>\r\n";
               $sitemap_contents .= "   </url>\r\n";
            }
            
            // get ending </urlset> tag
            $sitemap_contents .= $this->getXmlUrlsetTagEnd();
         }
         else
         {
            $error_msg .= '<li>ERROR: No sitemap file to create</li>';
         }
      }
      // no SQL passed so build sitemap from URL array only if present
      else if (is_array($this->url_arr))
      {
         // get opening <?xml> and <urlset> start tag
         $sitemap_contents = $this->getXmlUrlsetTagStart();
         
         // if url array is present, build the URL entries for them
         $sitemap_contents .= $this->getUrlArraySitemapUrlTags();
         
         // get ending </urlset> tag
         $sitemap_contents .= $this->getXmlUrlsetTagEnd();
      }
      
      return $sitemap_contents;
   }






   /**
     * Builds the contents of a single sitemap file.
     * @param  string $sql_limit  SQL LIMIT clause
     * @access public
     * @return string $sitemap_contents
     */
   function buildSitemapContentsUrlsOnly($sql_limit)
   {
      // start processing SQL if passed
      if ($this->sql)
      {
         echo "Processing $this->sql on line " . __LINE__ . ' in fn ' . __function__ . '<hr>';
         // <loc> url template cannot be blank
         if (empty($this->loc_url_template))
            die("ERROR: \$this->loc_url_template cannot be empty. Line " . __LINE__ .
                ' in ' . __FILE__ . ' from function ' . __FUNCTION__);

         preg_match_all("/\[[^\[\]]+\]/", $this->loc_url_template, $matches);

         $loc_url_template_arr_size = count($matches[0]);
         $db_field_name_arr_size = count($this->db_field_name_arr);

         // start swapping data as long as array sizes match up
         if ($db_field_name_arr_size == $loc_url_template_arr_size)
         {
            $loc_url_template = $this->loc_url_template;

            // replace each [string] replacement with the appropriate db column.
            // *** IMPORTANT: USE ONLY $loc_url_template and NOT $this->loc_url_template as that will overwrite ***
            //     the template causing subsequent calls to the buildSitemapContents method to fail in cases
            //     where we have to split the Sitemap into several smaller files!
            foreach ($this->db_field_name_arr as $db_field_name)
               $loc_url_template = preg_replace("/\[[^\[\]]+\]/", "\$query_data->$db_field_name", $loc_url_template, 1);
         }
         // if array sizes don't match, then user has missed including some data
         else
         {
            die("ERROR: DB field name array and URL template array do not contain the same number of elements. \$db_field_name_arr_size: $db_field_name_arr_size, \$loc_url_template_arr_size: $loc_url_template_arr_size. Line " . __LINE__ . ' in file ' . __FILE__ . '.');
         }

         // assemble full SQL string
         $sql = "$this->sql $sql_limit";
         #echo interpolateSQL($pdo, $sql, $params = ['cat_name' => $cat_name, 'cat_description' => $cat_description, 'meta_title' => $meta_title, 'meta_description' => $meta_description, 'cat_id' => $cat_id]); // sql debugging
         $stmt = $this->pdo->prepare($sql);
         $stmt->execute([]);


         if ($stmt->rowCount() > 0)
         {
            // if url array is present, build the URL entries for them
            $sitemap_contents .= $this->getUrlArraySitemapUrlTags();

            while ($query_data = $stmt->fetch())
            {
               #$sitemap_contents .= "   <!-- $query_data->client_name - $query_data->page_name -->\r\n";
               $sitemap_contents .= "   <url>\r\n";

               // use supplied $http_host value or DB field template only
               $sitemap_contents .= ($this->use_hostname_prefix) ? "      <loc>https://$this->http_host" : "      <loc>";


               // evaluate the URL template to substitute the DB variables
               eval('$sitemap_contents .= "' . $loc_url_template . '";?>');

               $sitemap_contents .= "</loc>\r\n";
               //$sitemap_contents .= "      <changefreq>$this->sitemap_changefreq</changefreq>\r\n";
               $sitemap_contents .= "   </url>\r\n";

               /////echo $sitemap_contents ."<br>";
            }

         }
         else
         {
            $error_msg .= '<li>ERROR: No sitemap file to create</li>';
         }
      }
      // no SQL passed so build sitemap from URL array only if present
      else if (is_array($this->url_arr))
      {
         // get opening <?xml> and <urlset> start tag
         $sitemap_contents = $this->getXmlUrlsetTagStart();

         // if url array is present, build the URL entries for them
         $sitemap_contents .= $this->getUrlArraySitemapUrlTags();

         // get ending </urlset> tag
         $sitemap_contents .= $this->getXmlUrlsetTagEnd();
      }

      return $sitemap_contents;
   }



   
   
   /**
     * Builds the contents of the <url> tags from an array.
     * @access public
     * @return string $sitemap_contents
     */
   function getUrlArraySitemapUrlTags()
   {
      // if url array is present, build the URL entries for them
      if (is_array($this->url_arr))
      {
         // URL array should come as URL|changefreq
         foreach ($this->url_arr as $val)
         {
            $val_arr = explode('|', $val);
            
            $sitemap_contents .= "   <url>\r\n";
            $sitemap_contents .= "      <loc>$val_arr[0]</loc>\r\n";
            //$sitemap_contents .= "      <changefreq>$val_arr[1]</changefreq>\r\n";
            $sitemap_contents .= "   </url>\r\n";
         }
      }
      
      return $sitemap_contents;
   }
   
   
   /**
     * Get the contents of the start of the XML and <urlset> Sitemap tag
     * @access public
     * @return string $sitemap_contents
     */
   function getXmlUrlsetTagStart()
   {
      $sitemap_contents = '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n";
      $sitemap_contents .= '<urlset xmlns="http://www.google.com/schemas/sitemap/0.84"' . "\r\n";
      $sitemap_contents .= 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\r\n";
      $sitemap_contents .= 'xsi:schemaLocation="http://www.google.com/schemas/sitemap/0.84' . "\r\n";
      $sitemap_contents .= 'http://www.google.com/schemas/sitemap/0.84/sitemap.xsd">' . "\r\n";
      
      return $sitemap_contents;
   }
   
   
   /**
     * Get the end </urlset> Sitemap tag
     * @access public
     * @return string $sitemap_contents
     */
   function getXmlUrlsetTagEnd()
   {
      $sitemap_contents = '</urlset>';

      return $sitemap_contents;
   }
}
?>