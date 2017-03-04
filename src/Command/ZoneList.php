<?php

namespace Cloudflare\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;
use GuzzleHttp\Client;

class ZoneList extends Command {

  protected $start = NULL;
  protected $end = NULL;
  protected $email;
  protected $key;
  protected $organizationFilter;
  protected $format;

  const API_BASE = 'https://api.cloudflare.com/client/v4/';

  /**
   * Perform an API request to Cloudflare.
   *
   * @param string $endpoint
   *   The API endpoint to hit. The endpoint is prefixed with the API_BASE.
   * @return array
   *   Decoded JSON body of the API request, if the request was successful.
   */
  protected function apiRequest($endpoint) {
    $client = new Client([
      'base_uri' => self::API_BASE,
      'headers' => [
        'X-Auth-Email' => $this->email,
        'X-Auth-Key' => $this->key,
      ],
    ]);
    $response = $client->request('GET', $endpoint);

    if ($response->getStatusCode() !== 200) {
      throw new \Exception('Error: ' . (string) $response->getBody());
    }

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * @inheritdoc
   */
  protected function configure() {
    $this
      ->setName('zone:list')
      ->setDescription('Audit a Drupal site to ensure it meets best practice')
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

    $results = $this->apiRequest('zones?per_page=1000');

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
        $organization_zones[$zone['owner']['name']][] = [
          'id' => $zone['id'],
          'domain' => $zone['name'],
          'status' => $zone['status'],
        ];
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
        $output->writeln("<info>YAML file written to ./output.yml.</info>");
        break;
    }

    $seconds = $this->timerEnd();
    $output->writeln("<info>Execution time: $seconds seconds</info>");
  }

  protected function timerStart() {
    $this->start = microtime(true);
  }

  protected function timerEnd() {
    $this->end = microtime(true);
    return (int) ($this->end - $this->start);
  }

}
