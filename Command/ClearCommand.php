<?php

namespace Tqd\MemcacheBundle\Command;

use Tqd\MemcacheBundle\Cache\AntiDogPileMemcache;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Provides a command-line interface for flushing memcache content
 */
#[AsCommand(name: 'memcache:clear', description: 'Invalidate all Memcache items')]
class ClearCommand extends Command
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
     * @var AntiDogPileMemcache
     */
    private $memcache;

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
        $this->memcache = $this->pools->get($pool);

        if (!$this->memcache->flush()) {
            $output->writeln('<error>ERROR</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>OK</info>');
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

}
