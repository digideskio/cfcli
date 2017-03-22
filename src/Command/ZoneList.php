<?php

namespace Cloudflare\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;

class ZoneList extends Command {

  protected $output = NULL;
  protected $start = NULL;
  protected $end = NULL;
  protected $apiCalls = 0;
  protected $email;
  protected $key;
  protected $organizationFilter;
  protected $format;
  protected $wafCheck = FALSE;
  protected $cdnCheck = FALSE;

  const API_BASE = 'https://api.cloudflare.com/client/v4/';
  const API_PER_PAGE = 1000;

  /**
   * Perform an API request to Cloudflare.
   *
   * @param string $endpoint
   *   The API endpoint to hit. The endpoint is prefixed with the API_BASE.
   * @return array
   *   Decoded JSON body of the API request, if the request was successful.
   */
  protected function apiRequest($endpoint) {
    $url = '';
    $time = 0;
    $client = new Client([
      'base_uri' => self::API_BASE,
      'headers' => [
        'X-Auth-Email' => $this->email,
        'X-Auth-Key' => $this->key,
        'User-Agent' => 'cfcli/1.0',
        'Accept' => 'application/json',
      ],
      'allow_redirects' => FALSE,
      'connect_timeout' => 5,
      'timeout' => 10,
      'on_stats' => function(TransferStats $stats) use (&$url, &$time) {
        $url = $stats->getEffectiveUri();
        $time = $stats->getTransferTime();
      }
    ]);
    $response = $client->request('GET', $endpoint);
    $this->apiCalls++;

    if ($response->getStatusCode() !== 200) {
      throw new \Exception('Error: ' . (string) $response->getBody());
    }

    // Debug logging.
    if ($this->output->isVerbose()) {
      $this->output->writeln(" > Debug: {$url} [{$time} seconds]");
    }

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * Load all zones you have access to. Takes care of pagination.
   *
   * @see https://api.cloudflare.com/#zone-list-zones
   *
   * @return array
   *   Decoded JSON body of the API request, if the request was successful.
   */
  protected function getAllZones() {
    $results = $this->apiRequest('zones?page=1&per_page=' . self::API_PER_PAGE);

    if ($results['result_info']['total_pages'] > 1) {
      for ($i = 2 ; $i <= $results['result_info']['total_pages'] ; $i++) {
        $loop_results = $this->apiRequest('zones?page=' . $i . '&per_page=' . self::API_PER_PAGE);
        $results['result'] = array_merge($results['result'], $loop_results['result']);
      }
    }

    return $results;
  }

  /**
   * @inheritdoc
   */
  protected function configure() {
    $this
      ->setName('zone:list')
      ->setDescription('Lists all zones that you have access to in Cloudflare, optionally filtered by an organization filter.')
      ->addOption(
        'email',
        'e',
        InputOption::VALUE_REQUIRED,
        'Your Cloudflare email address.'
      )
      ->addOption(
        'key',
        'k',
        InputOption::VALUE_REQUIRED,
        'Your Cloudflare API key.'
      )
      ->addOption(
        'organization-filter',
        'o',
        InputOption::VALUE_REQUIRED,
        'You can filter the domain list by a organization. Regex is allowed.',
        '.*'
      )
      ->addOption(
        'format',
        'f',
        InputOption::VALUE_REQUIRED,
        'Desired output format.',
        'yaml'
      )
      ->addOption(
        'waf',
        'w',
        InputOption::VALUE_NONE,
        'If set, all zones will do an additional API lookup to see if the WAF is enabled or not.'
      )
      ->addOption(
        'cdn',
        'c',
        InputOption::VALUE_NONE,
        'If set, all zones will do an additional API lookup to see if the CDN is enabled or not.'
      )
    ;
  }

  /**
   * @inheritdoc
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->timerStart();

    $this->email = $input->getOption('email');
    $this->key = $input->getOption('key');
    $this->organizationFilter = $input->getOption('organization-filter');
    $this->format = $input->getOption('format');
    $this->wafCheck = $input->getOption('waf');
    $this->cdnCheck = $input->getOption('cdn');
    $this->output = $output;

    $io = new SymfonyStyle($input, $output);
    $io->title('Cloudflare zone list report');

    // Get all zone data including pagination.
    $results = $this->getAllZones();

    $organization_zones = [];
    $counts = [
      'total' => 0,
      'active' => 0,
      'pending' => 0,
      'initializing' => 0,
      'moved' => 0,
      'deleted' => 0,
      'deactivated read only' => 0,
    ];
    $cdn_enabled_count = 0;
    $waf_enabled_count = 0;
    foreach ($results['result'] as $zone) {
      if ($zone['owner']['type'] === 'organization' && preg_match('/' . $this->organizationFilter . '/', $zone['owner']['name'])) {

        // Base zone details.
        $zone_details = [
          'id' => $zone['id'],
          'domain' => $zone['name'],
          'status' => $zone['status'],
        ];

        // Optional WAF check.
        if ($this->wafCheck) {
          $waf_enabled = $this->apiRequest("zones/{$zone['id']}/settings/waf")['result']['value'];
          $zone_details['waf'] = $waf_enabled === 'on';
          if ($zone_details['waf']) {
            $waf_enabled_count++;
          }
        }

        // Optional CDN check.
        if ($this->cdnCheck) {
          $zone_details['cdn'] = FALSE;
          $pagerules = $this->apiRequest("zones/{$zone['id']}/pagerules?status=active&order=status&direction=desc&match=all")['result'];

          // This logic seems overly complex.
          foreach ($pagerules as $pagerule) {
            if ($pagerule['targets'][0]['constraint']['value'] === '*' . $zone['name'] . '/*') {
              foreach ($pagerule['actions'] as $action) {
                if ($action['id'] === 'cache_level') {
                  $zone_details['cdn'] = $action['value'];
                  if ($zone_details['cdn'] === 'cache_everything') {
                    $cdn_enabled_count++;
                  }
                }
              }
            }
          }

          if (!$zone_details['cdn']) {
            $io->warning('Could not find a page rule for global caching on domain ' . $zone['name']);
            $zone_details['cdn'] = 'not setup';
          }
        }

        $organization_zones[$zone['owner']['name']][] = $zone_details;

        // Increment counts.
        $counts[$zone['status']]++;
        $counts['total']++;
      }
    }

    $variables = [
      'meta' => [
        'organization count' => count($organization_zones),
        'zone counts' => $counts,
        'waf check' => $this->wafCheck,
        'cdn check' => $this->cdnCheck,
      ],
      'zones' => $organization_zones,
    ];

    if ($this->wafCheck) {
      $variables['meta']['waf enabled count'] = $waf_enabled_count;
    }

    if ($this->cdnCheck) {
      $variables['meta']['cdn enabled count'] = $cdn_enabled_count;
    }

    $seconds = $this->timerEnd();
    $io->text("Execution time: $seconds seconds, with $this->apiCalls API queries.");
    $variables['meta']['execution time'] = $seconds;
    $variables['meta']['api calls'] = $this->apiCalls;

    switch ($this->format) {
      case 'json':
        $json = json_encode($variables, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        file_put_contents('./reports/zone-list.json', $json);
        $io->success('JSON file written to ./reports/zone-list.json');
        break;

      case 'html':
        $this->writeHTMLReport($io, $variables, './reports/zone-list.html');
        $io->success('HTML file written to ./reports/zone-list.html');
        break;

      case 'yaml':
      default:
        $yaml = Yaml::dump($variables, 3);
        file_put_contents('./reports/zone-list.yml', $yaml);
        $io->success('YAML file written to ./reports/zone-list.yml');
        break;
    }
  }

  /**
   * Convert the results into HTML.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   The output style.
   * @param array $variables
   *   Variables to output in the template.
   * @param string $filepath
   *   The path to the HTML file.
   */
  protected function writeHTMLReport(SymfonyStyle $io, array $variables = [], $filepath) {
    $loader = new \Twig_Loader_Filesystem(__DIR__ . '/../../templates');
    $twig = new \Twig_Environment($loader, array(
      'cache' => sys_get_temp_dir() . '/cfcli/cache',
      'auto_reload' => TRUE,
    ));
    $template = $twig->load('zonelist.html.twig');
    $contents = $template->render([
      'meta' => $variables['meta'],
      'zones' => $variables['zones'],
    ]);

    if (is_file($filepath) && !is_writable($filepath)) {
      throw new \RuntimeException("Cannot overwrite file: $filepath");
    }

    file_put_contents($filepath, $contents);
  }

  protected function timerStart() {
    $this->start = microtime(true);
  }

  protected function timerEnd() {
    $this->end = microtime(true);
    return (int) ($this->end - $this->start);
  }

}
