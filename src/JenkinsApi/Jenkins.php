<?php
/**
 * JenkinsNew.php
 *
 * @package    Atlas
 * @author     Christopher Biel <christopher.biel@jungheinrich.de>
 * @copyright  2013 Jungheinrich AG
 * @license    Proprietary license
 * @version    $Id$
 */

namespace JenkinsApi;

use InvalidArgumentException;
use JenkinsApi\Exceptions\JenkinsApiException;
use JenkinsApi\Item\Build;
use JenkinsApi\Item\Executor;
use JenkinsApi\Item\Job;
use JenkinsApi\Item\LastBuild;
use JenkinsApi\Item\Node;
use JenkinsApi\Item\Queue;
use JenkinsApi\Item\View;
use RuntimeException;
use stdClass;

/**
 * Wrapper for general
 *
 * @package    JenkinsApi
 * @author     Christopher Biel <christopher.biel@jungheinrich.de>
 * @version    $Id$
 */
class Jenkins
{
    const FORMAT_OBJECT = 'asObject';
    const FORMAT_XML = 'asXml';

    /**
     * @var bool
     */
    protected $verbose = false;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var string
     */
    protected $username;

    /**
     * @var string
     */
    protected $password;

    /**
     * @var string
     */
    protected $urlExtension = '/api/json';

    /**
     * Whether or not to retrieve and send anti-CSRF crumb tokens
     * with each request
     *
     * Defaults to false for backwards compatibility
     *
     * @var boolean
     */
    protected $crumbsEnabled = false;

    /**
     * The anti-CSRF crumb to use for each request
     *
     * Set when crumbs are enabled, by requesting a new crumb from Jenkins
     *
     * @var string
     */
    protected $crumb;

    /**
     * The header to use for sending anti-CSRF crumbs
     *
     * Set when crumbs are enabled, by requesting a new crumb from Jenkins
     *
     * @var string
     */
    protected $crumbRequestField;

    /**
     * @var null|string
     */
    protected $proxyUrl;
    /**
     * @var array
     */
    protected $customCurlSettings;

    /**
     * @param string $baseUrl
     * @param string $username
     * @param string $password
     * @param string $proxyUrl
     * @param array $customCurlSettings
     */
    public function __construct($baseUrl, $username = '', $password = '', $proxyUrl = null, $customCurlSettings = [])
    {
        $this->baseUrl = $baseUrl . ((substr($baseUrl, -1) === '/') ? '' : '/');
        $this->username = $username;
        $this->password = $password;
        $this->proxyUrl = $proxyUrl;
        $this->customCurlSettings = $customCurlSettings;
    }

    /**
     * @param string|Job $jobName
     * @param int|string $buildNumber
     *
     * @return Build
     */
    public function getBuild($jobName, $buildNumber)
    {
        return new Build($buildNumber, $jobName, $this);
    }

    /**
     * @param string $jobName
     *
     * @return Job
     */
    public function getJob($jobName)
    {
        return new Job($jobName, $this);
    }

    /**
     * @return Job[]
     */
    public function getJobs()
    {
        $data = $this->get('api/json');

        $jobs = array();
        foreach ($data->jobs as $job) {
            $jobs[$job->name] = $this->getJob($job->name);
        }

        return $jobs;
    }

    /**
     * @return Queue
     * @throws RuntimeException
     */
    public function getQueue()
    {
        return new Queue($this);
    }

    /**
     * @param string $url
     * @param int $depth
     * @param array $params
     * @param array $curlOpts
     * @param bool $raw
     * @return stdClass
     * @throws JenkinsApiException
     */
    public function get($url, $depth = 1, $params = array(), array $curlOpts = [], $raw = false)
    {
//        $url = str_replace(' ', '%20', sprintf('%s' . $url . '?depth=' . $depth, $this->_baseUrl));
        $url = sprintf('%s', $this->baseUrl) . $url . '?depth=' . $depth;
        if ($params) {
            foreach ($params as $key => $val) {
                $url .= '&' . $key . '=' . $val;
            }
        }
        $curl = $this->initCurl($url);
        if ($curlOpts) {
            curl_setopt_array($curl, $curlOpts);
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        if ($this->username) {
            curl_setopt($curl, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        }

        $ret = curl_exec($curl);

        $response_info = curl_getinfo($curl);

        if (200 != $response_info['http_code']) {
            throw new JenkinsApiException(
                sprintf(
                    'Error during getting information from url %s (Response: %s)', $url, $response_info['http_code']
                )
            );
        }

        if (curl_errno($curl)) {
            throw new JenkinsApiException(
                sprintf('Error during getting information from url %s (%s)', $url, curl_error($curl))
            );
        }
        if ($raw) {
            return $ret;
        }
        $data = json_decode($ret);
        if (!$data instanceof stdClass) {
            throw new JenkinsApiException('Error during json_decode');
        }

        return $data;
    }

    /**
     * @param string       $url
     * @param array|string $parameters
     * @param array        $curlOpts
     *
     * @throws RuntimeException
     * @return bool
     */
    public function post($url, $parameters = [], array $curlOpts = [])
    {
        $url = sprintf('%s', $this->baseUrl) . $url;

        $curl = $this->initCurl($url);
        if ($curlOpts) {
            curl_setopt_array($curl, $curlOpts);
        }
        curl_setopt($curl, CURLOPT_POST, 1);
        if (is_array($parameters)) {
            $parameters = http_build_query($parameters);
        }
        curl_setopt($curl, CURLOPT_POSTFIELDS, $parameters);

        if ($this->username) {
            curl_setopt($curl, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        }

        $headers = (isset($curlOpts[CURLOPT_HTTPHEADER])) ? $curlOpts[CURLOPT_HTTPHEADER] : array();

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $return = curl_exec($curl);
        return (curl_errno($curl)) ?: $return;
    }

    /**
     * @return boolean
     */
    public function isAvailable()
    {
        $curl = $this->initCurl($this->baseUrl . '/api/json');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        if ($this->username) {
            curl_setopt($curl, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        }

        curl_exec($curl);

        if (curl_errno($curl)) {
            return false;
        } else {
            try {
                $this->getQueue();
            } catch (JenkinsApiException $e) {
                return false;
            }
        }

        return true;
    }

    public function getCrumbHeader()
    {
        return "$this->crumbRequestField: $this->crumb";
    }

    /**
     * Get the status of anti-CSRF crumbs
     *
     * @return boolean Whether or not crumbs have been enabled
     */
    public function areCrumbsEnabled()
    {
        return $this->crumbsEnabled;
    }

    public function requestCrumb()
    {
        $url = sprintf('%s/crumbIssuer/api/json', $this->baseUrl);

        $curl = $this->initCurl($url);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        if ($this->username) {
            curl_setopt($curl, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        }

        $ret = curl_exec($curl);

        if (curl_errno($curl)) {
            throw new JenkinsApiException('Error getting csrf crumb');
        }

        $crumbResult = json_decode($ret);

        if (!$crumbResult instanceof stdClass) {
            throw new JenkinsApiException('Error during json_decode of csrf crumb');
        }

        return $crumbResult;
    }

    /**
     * Enable the use of anti-CSRF crumbs on requests
     *
     * @return void
     */
    public function enableCrumbs()
    {
        $this->crumbsEnabled = true;

        $crumbResult = $this->requestCrumb();

        if (!$crumbResult || !is_object($crumbResult)) {
            $this->crumbsEnabled = false;

            return;
        }

        $this->crumb = $crumbResult->crumb;
        $this->crumbRequestField = $crumbResult->crumbRequestField;
    }

    /**
     * Get the currently building jobs
     *
     * @param string $outputFormat One of the FORMAT_* constants
     *
     * @return Item\Job[]
     */
    public function getCurrentlyBuildingJobs($outputFormat = self::FORMAT_OBJECT)
    {
        $url = sprintf(
            '%s/api/xml?%s',
            $this->baseUrl,
            'tree=jobs[name,url,color]&xpath=/hudson/job[ends-with(color/text(),%22_anime%22)]&wrapper=jobs'
        );

        $curl = $this->initCurl($url);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        if ($this->username) {
            curl_setopt($curl, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        }

        $ret = curl_exec($curl);

        if (curl_errno($curl)) {
            throw new JenkinsApiException(
                sprintf(
                    'Error during getting all currently building jobs on %s (%s)', $this->baseUrl, curl_error($curl)
                )
            );
        }

        $xml = simplexml_load_string($ret);
        $jobs = $xml->xpath('/jobs/job');

        switch ($outputFormat) {
            case self::FORMAT_OBJECT:
                $buildingJobs = [];
                foreach ($jobs as $job) {
                    $buildingJobs[] = new Job($job->name, $this);
                }
                return $buildingJobs;
            case self::FORMAT_XML:
                return $jobs;
            default:
                throw new InvalidArgumentException('Output format "' . $outputFormat . '" is unknown!');
        }
    }

    /**
     * Get the last builds from the currently building jobs
     *
     * @return LastBuild[]
     */
    public function getLastBuildsFromCurrentlyBuildingJobs()
    {
        $jobs = $this->getCurrentlyBuildingJobs();
        $lastBuilds = [];
        foreach ($jobs as $job) {
            $lastBuilds[] = $job->getLastBuild();
        }

        return $lastBuilds;
    }

    /**
     * @return View[]
     */
    public function getViews()
    {
        $data = $this->get('api/json');
        $views = array();
        foreach ($data->views as $view) {
            $views[] = $this->getView($view->name);
        }
        return $views;
    }

    /**
     * @return View|null
     */
    public function getPrimaryView()
    {
        $data = $this->get('api/json');

        $primaryView = null;
        if (property_exists($data, 'primaryView')) {
            $primaryView = $this->getView($data->primaryView->name);
        }

        return $primaryView;
    }

    /**
     * @param string $jobname
     * @param string $xmlConfiguration
     *
     * @param string $project
     * @throws JenkinsApiException
     */
    public function createJob($jobname, $xmlConfiguration, $project = null)
    {
        $baseUrl =  $this->getBaseUrl();
        if ($project) {
            $baseUrl .= 'job/'.rawurlencode($project);
        }

        $url = sprintf('%s/createItem?name=%s', $baseUrl, rawurlencode($jobname));

        $this->postJob($jobname, $xmlConfiguration, $url);
    }

    public function updateJob($jobname, $xmlConfiguration, $project = null)
    {
        $baseUrl =  $this->getBaseUrl();
        if ($project) {
            $baseUrl .= 'job/'.rawurlencode($project);
        }

        $url = sprintf('%s/job/%s/config.xml', $baseUrl, rawurlencode($jobname));

        $this->postJob($jobname, $xmlConfiguration, $url);
    }

    /**
     * @return Node[]
     */
    public function getNodes()
    {
        $data = $this->get('computer/api/json');

        foreach ($data->computer as $node) {
            yield new Node($node->displayName, $this);
        }
    }

    /**
     * @return Executor[]
     */
    public function getExecutors()
    {
        foreach ($this->getNodes() as $node) {
            yield $node->getExecutors();
        }
    }

    /**
     * Go into prepare shutdown mode.
     * This prevents new jobs beeing started
     */
    public function prepareShutdown()
    {
        $this->post('quietDown');
    }

    /**
     * Exit prepare shutdown mode
     * This allows jobs beeing started after shutdown prepare (but before actual restart)
     */
    public function cancelPrepareShutdown()
    {
        $this->post('cancelQuietDown');
    }

    /**
     * @param string $viewName
     *
     * @return View
     * @throws RuntimeException
     */
    public function getView($viewName)
    {
        return new View($viewName, $this);
    }

    /**
     * Disable the use of anti-CSRF crumbs on requests
     *
     * @return void
     */
    public function disableCrumbs()
    {
        $this->crumbsEnabled = false;
    }

    /**
     * @return string
     */
    public function getUrlExtension()
    {
        return $this->urlExtension;
    }

    /**
     * @param string $urlExtension
     */
    public function setUrlExtension($urlExtension)
    {
        $this->urlExtension = $urlExtension;
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * @param string $baseUrl
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * @return boolean
     */
    public function isVerbose()
    {
        return $this->verbose;
    }

    /**
     * @param boolean $verbose
     */
    public function setVerbose($verbose)
    {
        $this->verbose = $verbose;
    }

    /**
     * @param $url
     * @return resource
     */
    protected function initCurl($url)
    {
        $ch = curl_init($url);

        if ($this->proxyUrl) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyUrl);
        }

        if ($this->customCurlSettings) {
            curl_setopt_array($ch, $this->customCurlSettings);
        }

        return $ch;
    }

    /**
     * @param $jobname
     * @param $xmlConfiguration
     * @param $url
     * @throws JenkinsApiException
     */
    protected function postJob($jobname, $xmlConfiguration, $url)
    {
        $curl = $this->initCurl($url);
        curl_setopt($curl, CURLOPT_POST, 1);

        curl_setopt($curl, CURLOPT_POSTFIELDS, $xmlConfiguration);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        if ($this->username) {
            curl_setopt($curl, CURLOPT_USERPWD, $this->username.":".$this->password);
        }

        $headers = array('Content-Type: text/xml');

        if ($this->areCrumbsEnabled()) {
            $headers[] = $this->getCrumbHeader();
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($curl);

        if (curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200) {
            throw new InvalidArgumentException(sprintf('Job %s already exists', $jobname));
        }
        if (curl_errno($curl)) {
            throw new JenkinsApiException(sprintf('Error creating job %s (%s)', $jobname, curl_error($curl)));
        }
    }
}
