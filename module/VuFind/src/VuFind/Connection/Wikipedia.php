<?php
/**
 * Wikipedia connection class
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2010.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Connection
 * @author   Chris Hallberg <challber@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:recommendation_modules Wiki
 */
namespace VuFind\Connection;
use VuFind\I18n\Translator\TranslatorAwareInterface;

/**
 * Wikipedia connection class
 *
 * @category VuFind2
 * @package  Connection
 * @author   Chris Hallberg <challber@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:recommendation_modules Wiki
 * @view     AuthorInfoFacets.phtml
 */
class Wikipedia implements TranslatorAwareInterface
{
    /**
     * HTTP client
     *
     * @var \Zend\Http\Client
     */
    protected $client;

    /**
     * Translator (or null if unavailable)
     *
     * @var \Zend\I18n\Translator\Translator
     */
    protected $translator = null;

    /**
     * Selected language
     *
     * @var string
     */
    protected $lang = 'en';

    /**
     * Log of Wikipedia pages already retrieved
     *
     * @var array
     */
    protected $pagesRetrieved = array();

    /**
     * Constructor
     *
     * @param \Zend\Http\Client $client HTTP client
     */
    public function __construct(\Zend\Http\Client $client)
    {
        $this->client = $client;
    }

    /**
     * Set a translator
     *
     * @param \Zend\I18n\Translator\Translator $translator Translator
     *
     * @return Wikipedia
     */
    public function setTranslator(\Zend\I18n\Translator\Translator $translator)
    {
        $this->translator = $translator;
        return $this;
    }

    /**
     * Get translator object.
     *
     * @return \Zend\I18n\Translator\Translator
     */
    public function getTranslator()
    {
        return $this->translator;
    }

    /**
     * Set language
     *
     * @param string $lang Language
     *
     * @return void
     */
    public function setLanguage($lang)
    {
        $this->lang = $lang;
    }

    /**
     * get
     *
     * This method is responsible for connecting to Wikipedia via the REST API
     * and pulling the content for the relevant author.
     *
     * @param string $author The author name to search for
     *
     * @return array
     */
    public function get($author)
    {
        // Don't retrieve the same page multiple times; this indicates a loop
        // that needs to be broken!
        if ($this->alreadyRetrieved($author)) {
            return array();
        }

        // Get information from Wikipedia API
        $uri = 'http://' . $this->lang . '.wikipedia.org/w/api.php' .
               '?action=query&prop=revisions&rvprop=content&format=php' .
               '&list=allpages&titles=' . urlencode($author);

        $response = $this->client->setUri($uri)->setMethod('GET')->send();
        if ($response->isSuccess()) {
            return $this->parseWikipedia(unserialize($response->getBody()));
        }
        return null;
    }

    /**
     * Check if a page has already been retrieved; if it hasn't, flag it as
     * retrieved for future reference.
     *
     * @param string $author Author being retrieved
     *
     * @return bool
     */
    protected function alreadyRetrieved($author)
    {
        if (isset($this->pagesRetrieved[$author])) {
            return true;
        }
        $this->pagesRetrieved[$author] = true;
        return false;
    }

    /**
     * Extract image information from an infobox
     *
     * @param string $infoboxStr Infobox text
     *
     * @return string
     */
    protected function extractImageFromInfoBox($infoboxStr)
    {
        $imageName = $imageCaption = null;

        // Get rid of the last pair of braces and split
        $infobox = explode("\n|", substr($infoboxStr, 2, -2));
        // Look through every row of the infobox
        foreach ($infobox as $row) {
            $data  = explode("=", $row);
            $key   = trim(array_shift($data));
            $value = trim(join("=", $data));

            // At the moment we only want stuff related to the image.
            switch (strtolower($key)) {
            case "img":
            case "image":
            case "image:":
            case "image_name":
                $imageName = str_replace(' ', '_', $value);
                break;
            case "caption":
            case "img_capt":
            case "image_caption":
                $imageCaption = $value;
                break;
            default:
                /* Nothing else... yet */
                break;
            }
        }

        return array($imageName, $imageCaption);
    }

    /**
     * Support method for parseWikipedia - extract infobox details
     *
     * @param array $body The Wikipedia response to parse
     *
     * @return string
     */
    protected function extractInfoBox($body)
    {
        // We are looking for the infobox inside "{{...}}"
        //   It may contain nested blocks too, thus the recursion
        preg_match_all('/\{([^{}]++|(?R))*\}/s', $body['*'], $matches);
        foreach ($matches[1] as $m) {
            // If this is the Infobox
            if (substr($m, 0, 8) == "{Infobox") {
                // Keep the string for later, we need the body block that follows it
                return "{".$m."}";
            }
        }

        return null;
    }

    /**
     * Support method for parseWikipedia - extract first image from body
     *
     * @param array $body The Wikipedia response to parse
     *
     * @return array
     */
    protected function extractImageFromBody($body)
    {
        $imageName = $imageCaption = null;
        $pattern = '/(\x5b\x5b)Image:([^\x5d]*)(\x5d\x5d)/U';
        preg_match_all($pattern, $body['*'], $matches);
        if (isset($matches[2][0])) {
            $parts = explode('|', $matches[2][0]);
            $imageName = str_replace(' ', '_', $parts[0]);
            if (count($parts) > 1) {
                $imageCaption = strip_tags(
                    preg_replace('/({{).*(}})/U', '', $parts[count($parts) - 1])
                );
            }
        }
        return array($imageName, $imageCaption);
    }

    /**
     * Support method for sanitizeWikipediaBody -- strip image/file links.
     *
     * @param string $body The Wikipedia response to sanitize
     *
     * @return string
     */
    protected function stripImageAndFileLinks($body)
    {
        // Remove unwanted image/file links
        // Nested brackets make this annoying: We can't add 'File' or 'Image' as
        //    mandatory because the recursion fails, or as optional because then
        //    normal links get hit.
        //    ... unless there's a better pattern? TODO
        // eg. [[File:Johann Sebastian Bach.jpg|thumb|Bach in a 1748 portrait by
        //     [[Elias Gottlob Haussmann|Haussmann]]]]
        $open    = "\\[";
        $close   = "\\]";
        $content = "(?>[^\\[\\]]+)";  // Anything but [ or ]
        // We can either find content or recursive brackets:
        $recursive_match = "($content|(?R))*";
        $body .= "[[file:bad]]";
        preg_match_all("/".$open.$recursive_match.$close."/Us", $body, $new_matches);
        // Loop through every match (link) we found
        if (is_array($new_matches)) {
            foreach ($new_matches as $nm) {
                foreach ((array)$nm as $n) {
                    // If it's a file link get rid of it
                    if (strtolower(substr($n, 0, 7)) == "[[file:"
                        || strtolower(substr($n, 0, 8)) == "[[image:"
                    ) {
                        $body = str_replace($n, "", $body);
                    }
                }
            }
        }
        return $body;
    }

    /**
     * Support method for parseWikipedia - fix up details in the body
     *
     * @param string $body The Wikipedia response to sanitize
     *
     * @return string
     */
    protected function sanitizeWikipediaBody($body)
    {
        // Cull our content back to everything before the first heading
        $body = trim(substr($body, 0, strpos($body, "==")));

        // Strip out links
        $body = $this->stripImageAndFileLinks($body);

        // Initialize arrays of processing instructions
        $pattern = array();
        $replacement = array();

        // Convert wikipedia links
        $pattern[] = '/(\x5b\x5b)([^\x5d|]*)(\x5d\x5d)/Us';
        $replacement[]
            = '<a href="___baseurl___?lookfor=%22$2%22&amp;type=AllFields">$2</a>';
        $pattern[] = '/(\x5b\x5b)([^\x5d]*)\x7c([^\x5d]*)(\x5d\x5d)/Us';
        $replacement[]
            = '<a href="___baseurl___?lookfor=%22$2%22&amp;type=AllFields">$3</a>';

        // Fix pronunciation guides
        $pattern[] = '/({{)pron-en\|([^}]*)(}})/Us';
        $replacement[] = $this->getTranslator()->translate("pronounced") . " /$2/";

        // Fix dashes
        $pattern[] = '/{{ndash}}/';
        $replacement[] = ' - ';

        // Removes citations
        $pattern[] = '/({{)[^}]*(}})/Us';
        $replacement[] = "";
        //  <ref ... > ... </ref> OR <ref> ... </ref>
        $pattern[] = '/<ref[^\/]*>.*<\/ref>/Us';
        $replacement[] = "";
        //    <ref ... />
        $pattern[] = '/<ref.*\/>/Us';
        $replacement[] = "";

        // Removes comments followed by carriage returns to avoid excess whitespace
        $pattern[] = '/<!--.*-->\n*/Us';
        $replacement[] = '';

        // Formatting
        $pattern[] = "/'''([^']*)'''/Us";
        $replacement[] = '<strong>$1</strong>';

        // Trim leading newlines (which can result from leftovers after stripping
        // other items above).  We want this to be greedy.
        $pattern[] = '/^\n*/s';
        $replacement[] = '';

        // Convert multiple newlines into two breaks
        // We DO want this to be greedy
        $pattern[] = "/\n{2,}/s";
        $replacement[] = '<br/><br/>';

        return preg_replace($pattern, $replacement, $body);
    }

    /**
     * Check for redirection in the Wikipedia response
     *
     * @param array $body Response body
     *
     * @return array
     */
    protected function checkForRedirect($body)
    {
        $name = $redirectTo = $page = null;

        // Loop through the pages and find the first that isn't a redirect:
        foreach ($body['query']['pages'] as $page) {
            $name = $page['title'];

            // Get the latest revision
            $page = array_shift($page['revisions']);
            // Check for redirection
            $as_lines = explode("\n", $page['*']);
            if (stristr($as_lines[0], '#REDIRECT')) {
                preg_match('/\[\[(.*)\]\]/', $as_lines[0], $matches);
                $redirectTo = $matches[1];
            } else {
                $redirectTo = false;
                break;
            }
        }

        return array($name, $redirectTo, $page);
    }

    /**
     * Extract body text
     *
     * @param array  $body       Body details
     * @param string $infoboxStr Infobox found within body (if any)
     *
     * @return string
     */
    protected function extractBodyText($body, $infoboxStr)
    {
        if ($infoboxStr) {
            // Start of the infobox
            $start  = strpos($body['*'], $infoboxStr);
            // + the length of the infobox
            $offset = strlen($infoboxStr);
            // Every after the infobox
            return substr($body['*'], $start + $offset);
        }
        // No infobox -- use whole thing:
        return $body['*'];
    }

    /**
     * _parseWikipedia
     *
     * This method is responsible for parsing the output from the Wikipedia
     * REST API.
     *
     * @param string $rawBody The Wikipedia response to parse
     *
     * @return array
     * @author Rushikesh Katikar <rushikesh.katikar@gmail.com>
     */
    protected function parseWikipedia($rawBody)
    {
        // Check if data exists or not
        if (isset($rawBody['query']['pages']['-1'])) {
            return null;
        }

        // Check for redirects; get some basic information:
        list($name, $redirectTo, $bodyArr) = $this->checkForRedirect($rawBody);

        // Recurse if we only found redirects:
        if ($redirectTo) {
            return $this->get($redirectTo);
        }

        /* Infobox */
        $infoboxStr = $this->extractInfoBox($bodyArr);

        /* Body */
        $bodyStr = $this->extractBodyText($bodyArr, $infoboxStr);
        $info = array(
            'name' => $name,
            'description' => $this->sanitizeWikipediaBody($bodyStr),
            'wiki_lang' => $this->lang,
        );

        /* Image */

        // Try to find an image in either the infobox or the body:
        if ($infoboxStr) {
            list($imageName, $imageCaption)
                = $this->extractImageFromInfoBox($infoboxStr);
        }
        if (!isset($imageName)) {
            list($imageName, $imageCaption) = $this->extractImageFromBody($bodyArr);
        }

        // Given an image name found above, look up the associated URL and add it to
        // our return array:
        if (isset($imageName)) {
            $imageUrl = $this->getWikipediaImageURL($imageName);
            if ($imageUrl != false) {
                $info['image'] = $imageUrl;
                if (isset($imageCaption)) {
                    $info['altimage'] = $imageCaption;
                }
            }
        }

        return $info;
    }

    /**
     * This method is responsible for obtaining an image URL based on a name.
     *
     * @param string $imageName The image name to look up
     *
     * @return mixed            URL on success, false on failure
     */
    protected function getWikipediaImageURL($imageName)
    {
        $url = "http://{$this->lang}.wikipedia.org/w/api.php" .
               '?prop=imageinfo&action=query&iiprop=url&iiurlwidth=150&format=php' .
               '&titles=Image:' . urlencode($imageName);

        try {
            $result = $this->client->setUri($url)->setMethod('GET')->send();
        } catch (\Exception $e) {
            return false;
        }
        if (!$result->isSuccess()) {
            return false;
        }

        if ($response = $result->getBody()) {
            if ($imageinfo = unserialize($response)) {
                if (isset($imageinfo['query']['pages']['-1']['imageinfo'][0]['url'])
                ) {
                    $imageUrl
                        = $imageinfo['query']['pages']['-1']['imageinfo'][0]['url'];
                }

                // Hack for wikipedia api, just in case we couldn't find it
                //   above look for a http url inside the response.
                if (!isset($imageUrl)) {
                    preg_match('/\"http:\/\/(.*)\"/', $response, $matches);
                    if (isset($matches[1])) {
                        $imageUrl = 'http://' .
                            substr($matches[1], 0, strpos($matches[1], '"'));
                    }
                }
            }
        }

        return isset($imageUrl) ? $imageUrl : false;
    }
}