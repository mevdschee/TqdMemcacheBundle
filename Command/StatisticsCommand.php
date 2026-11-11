<?php

namespace Tqd\MemcacheBundle\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Provides a command-line interface for viewing cache pool stats
 * Based on Beryllium\CacheBundle by Kevin Boyd <beryllium@beryllium.ca>
 */
#[AsCommand(name: 'memcache:statistics', description: 'Display Memcache statistics')]
class StatisticsCommand extends Command
{
    /**
     * @var ContainerInterface Locator for the configured pools
     */
    private $pools;

    public function __construct(ContainerInterface $pools)
    {
        $this->pools = $pools;
        parent::__construct();
    }

   /**
    * Configure the CLI task
    *
    * @return void
    */
   protected function configure(): void
   {
      $this
        ->setDefinition(array(
            new InputArgument('pool', InputArgument::REQUIRED, 'The pool'),
        ));
   }

   /**
    * Execute the CLI task
    *
    * @param InputInterface  $input  Command input
    * @param OutputInterface $output Command output
    *
    * @return int
    */
   protected function execute(InputInterface $input, OutputInterface $output): int
   {
     $pool = $input->getArgument('pool');
     if (!$this->pools->has($pool)) {
         $output->writeln("<error>pool '$pool' is not found</error>");
         return Command::FAILURE;
     }
     $memcache = $this->pools->get($pool);
     $output->writeln($this->formatStats($memcache->getExtendedStats()));
     return Command::SUCCESS;
   }

   /**
    * Choose the pool
    *
    * @param InputInterface  $input  Input interface
    * @param OutputInterface $output Output interface
    *
    * @see Command
    * @return void
    */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (!$input->getArgument('pool')) {
            $pool = (new InteractHelper())->askForPool($this, $input, $output);
            $input->setArgument('pool', $pool);
        }
    }

   /**
    * Format the raw array for the command line report
    *
    * @param array $stats An array of memcache::extendedstats
    *
    * @return string ConsoleComponent-formatted output, suitable for ->writeln() usage
    */
    protected function formatStats($stats)
    {
        if (!$stats) {
        return "No statistics returned.\n";
        }

        $out = "Servers found: " . count($stats) . "\n\n";
        foreach ($stats as $host => $item) {
            if (!is_array($item) || count($item) == 0) {
                $out .= "  <error>" . $host . "</error>\n";

                continue;
            }

            $out .= "<info>Host:\t" . $host . "</info>\n";
            $out .= "\tUsage: " . $this->formatUsage($item['bytes'], $item['limit_maxbytes']) . "\n";
            $out .= "\tUptime: " . $this->formatUptime($item['uptime']) . "\n";
            $out .= "\tOpen Connections: " . $item['curr_connections'] . "\n";
            $out .= "\tHits: " . $item['get_hits'] . "\n";
            $out .= "\tMisses: " . $item['get_misses'] . "\n";
            if ($item['get_hits'] + $item['get_misses'] > 0 ) {
                $out .= "\tHelpfulness: " . round($item['get_hits'] / ($item['get_hits'] + $item['get_misses']) * 100, 2) . "%\n";
            }
        }

        return $out;
    }

   /**
    * Format the usage stats
    *
    * @param integer $bytes    Cache usage (in bytes)
    * @param integer $maxbytes Cache maximum size (in bytes)
    *
    * @return string A short string with friendly formatting
    */
   protected function formatUsage($bytes, $maxbytes )
   {
     if (!is_numeric($maxbytes) || $maxbytes < 1) {
       return '(undefined)';
     }

     $out  = round($bytes / $maxbytes, 3) . "% (";
     $out .= round($bytes / 1024 / 1024, 2) . 'MB of ';
     $out .= round($maxbytes / 1024 / 1024, 2) . 'MB)';

     return $out;
   }

   /**
    * Formats the uptime to be friendlier
    *
    * @param integer $uptime Cache server uptime (in seconds)
    *
    * @return string A short string with friendly formatting
    */
   protected function formatUptime($uptime )
   {
     $days = floor($uptime / 24 / 60 / 60);
     $daysRemainder = $uptime - ($days * 24 * 60 * 60);
     $hours = floor($daysRemainder / 60 / 60);
     $hoursRemainder = $daysRemainder - ($hours * 60 * 60);
     $minutes = floor($hoursRemainder / 60);
     $minutesRemainder = $hoursRemainder - ($minutes * 60);
     $seconds = $minutesRemainder;

     $out = $uptime . ' seconds (';
     if ($days > 0) {
         $out .= $days . ' days, ';
     }
     if ($hours > 0) {
         $out .= $hours . ' hours, ';
     }
     if ($minutes > 0) {
         $out .= $minutes . ' minutes, ';
     }
     if ($seconds > 0) {
         $out .= $seconds . ' seconds';
     }

     return $out . ')';
   }
}
