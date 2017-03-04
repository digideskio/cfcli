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
  protected $email;
  protected $key;
  protected $organizationFilter;
  protected $format;
  protected $wafCheck = FALSE;

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
      'timeout' => 5,
      'on_stats' => function(TransferStats $stats) use (&$url, &$time) {
        $url = $stats->getEffectiveUri();
        $time = $stats->getTransferTime();
      }
    ]);
    $response = $client->request('GET', $endpoint);

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
    $this->output = $output;

    $io = new SymfonyStyle($input, $output);
    $io->title('Cloudflare zone list report');

    // Get all zone data including pagination.
    $results = $this->getAllZones();

    $organization_zones = [];
    $counts = [
      'active' => 0,
      'pending' => 0,
      'initializing' => 0,
      'moved' => 0,
      'deleted' => 0,
      'deactivated read only' => 0,
    ];
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
        }

        $organization_zones[$zone['owner']['name']][] = $zone_details;

        // Increment counts.
        $counts[$zone['status']]++;
      }
    }

    $variables = [
      'meta' => [
        'organization count' => count($organization_zones),
        'zone counts' => $counts,
      ],
      'zones' => $organization_zones,
    ];

    // @TODO implement more output formats.
    switch ($this->format) {
      case 'yaml':
      default:
        $yaml = Yaml::dump($variables, 3);
        file_put_contents('./output.yml', $yaml);
        $io->success("YAML file written to output.yml");
        break;
    }

    $seconds = $this->timerEnd();
    $io->text("Execution time: $seconds seconds.");
  }

  protected function timerStart() {
    $this->start = microtime(true);
  }

  protected function timerEnd() {
    $this->end = microtime(true);
    return (int) ($this->end - $this->start);
  }

}
