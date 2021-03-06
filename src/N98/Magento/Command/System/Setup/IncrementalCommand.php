<?php

namespace N98\Magento\Command\System\Setup;

use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IncrementalCommand extends AbstractMagentoCommand
{
    const TYPE_MIGRATION_STRUCTURE = 'structure';
    const TYPE_MIGRATION_DATA      = 'data';
    protected $_output;
    
    /**
    * Holds our copy of teh global config.  
    *
    * Loaded to avoid grabbing the cached version, and so 
    * we still have all our original information when we 
    * destroy the real configuration
    * @var mixed $_secondConfig
    */    
    protected $_secondConfig;

    protected $_eventStash;

    protected function _loadSecondConfig()
    {
        $config = new \Mage_Core_Model_Config;
        $config->loadBase();                    //get app/etc        
        $this->_secondConfig = \Mage::getConfig()->loadModulesConfiguration('config.xml',$config);        
    }
    protected function _getAllSetupResourceObjects()
    {        
        $config = $this->_secondConfig;        
        $resources = $config->getNode('global/resources')->children();        
        $setup_resources = array();
        foreach($resources as $name=>$resource)
        {            
            if (!$resource->setup) {                
                continue;
            }
            $className = 'Mage_Core_Model_Resource_Setup';            
            if (isset($resource->setup->class)) {
                $className = $resource->setup->getClassName();
            }
            
            $setup_resources[$name] = new $className($name);
        }
        return $setup_resources;
    }
    
    protected function _getResource()
    {
        return \Mage::getResourceSingleton('core/resource');
    }
    
    protected function _getAvaiableDbFilesFromResource($setup_resource,$args=array())
    {        
        $result = $this->_callProtectedMethodFromObject('_getAvailableDbFiles',$setup_resource, $args);
        
        //an install runs the install script first, then any upgrades
        if($args[0] == \Mage_Core_Model_Resource_Setup::TYPE_DB_INSTALL)
        {
            $args[0] = \Mage_Core_Model_Resource_Setup::TYPE_DB_UPGRADE;
            $args[1] = $result[0]['toVersion'];
            $result = array_merge($result, $this->_callProtectedMethodFromObject('_getAvailableDbFiles',$setup_resource, $args));
        }
        return $result;
    }
    
    protected function _getAvaiableDataFilesFromResource($setup_resource,$args=array())
    {
        $result = $this->_callProtectedMethodFromObject('_getAvailableDataFiles',$setup_resource, $args);
        if($args[0] == \Mage_Core_Model_Resource_Setup::TYPE_DATA_INSTALL)
        {
            $args[0] = \Mage_Core_Model_Resource_Setup::TYPE_DATA_UPGRADE;
            $args[1] = $result[0]['toVersion'];
            $result = array_merge($result, $this->_callProtectedMethodFromObject('_getAvailableDbFiles',$setup_resource, $args));
        }        
        return $result;    
    }
    
    protected function _callProtectedMethodFromObject($method, $object, $args=array())
    {
        $r = new \ReflectionClass($object);
        $m = $r->getMethod('_getAvailableDbFiles');
        $m->setAccessible(true);
        return $m->invokeArgs($object, $args);    
    }
    
    protected function _setProtectedPropertyFromObjectToValue($property, $object, $value)
    {
        $r = new \ReflectionClass($object);
        $p = $r->getProperty($property);
        $p->setAccessible(true);            
        $p->setValue($object, $value);
    }
    
    protected function _getProtectedPropertyFromObject($property, $object)
    {
        $r = new \ReflectionClass($object);
        $p = $r->getProperty($property);
        $p->setAccessible(true);            
        return $p->getValue($object);    
    }
    
    protected function _getDbVersionFromName($name)
    {
        return $this->_getResource()->getDbVersion($name);
    }
    
    protected function _getDbDataVersionFromName($name)
    {
        return $this->_getResource()->getDataVersion($name);
    }
    
    protected function _getConfiguredVersionFromResourceObject($object)
    {
        $module_config  = $this->_getProtectedPropertyFromObject('_moduleConfig', $object);
        return $module_config->version;       
    }
    protected function _getAllSetupResourceObjectThatNeedUpdates($setup_resources=false)
    {
        $setup_resources = $setup_resources ? $setup_resources : $this->_getAllSetupResourceObjects();
        $needs_update = array();
        foreach($setup_resources as $name=>$setup_resource)
        {
            ##$this->_log('Examining: ' . get_class($setup_resource) . ' for ' . $name );            
            ##$this->_log('QUESTION: Is going to the global core/resource bad here?');
            $db_ver         = $this->_getDbVersionFromName($name);
            $db_data_ver    = $this->_getDbDataVersionFromName($name);
            $config_ver     = $this->_getConfiguredVersionFromResourceObject($setup_resource);         
            
            ##$this->_log('Database Version: ' . $db_ver);
            ##$this->_log('Config   Version: ' . $config_ver);
            
            if(
                (string)$config_ver == (string)$db_ver          && //structure
                (string)$config_ver == (string)$db_data_ver        //data
            )
            {            
                continue;
            }
            $needs_update[$name] = $setup_resource;
        }        
        return $needs_update;
    }
    
    protected function _log($message)
    {
        $this->_output->writeln($message);
    }
    
    protected function _setOutput($output)
    {
        $this->_output = $output;
    }
    protected function _outputUpdateInformation($needs_update)
    {
        $output = $this->_output;
        foreach($needs_update as $name=>$setup_resource)
        {
            $db_ver         = $this->_getDbVersionFromName($name);
            $db_data_ver    = $this->_getDbDataVersionFromName($name);
            $config_ver     = $this->_getConfiguredVersionFromResourceObject($setup_resource);         
        
            $module_config  = $this->_getProtectedPropertyFromObject('_moduleConfig', $setup_resource);
            $output->writeln('+--------------------------------------------------+'); 
            $output->writeln('Resource Name:             ' . $name);
            $output->writeln('For Module:                ' . $module_config->getName());
            $output->writeln('Class:                     ' .  get_class($setup_resource));
            $output->writeln('Current Structure Version: ' . $db_ver);
            $output->writeln('Current Data Version:      ' . $db_data_ver);            
            $output->writeln('Configured Version:        ' . $config_ver);                        
            
            $args = array(
                '',
                (string) $db_ver,
                (string) $config_ver,
            );
            
            $args[0] = $db_ver ? \Mage_Core_Model_Resource_Setup::TYPE_DB_UPGRADE : \Mage_Core_Model_Resource_Setup::TYPE_DB_INSTALL;
            $output->writeln('Structure Files to Run: ');
            $files_structure = $this->_getAvaiableDbFilesFromResource($setup_resource, $args);
            $this->_outputFileArray($files_structure, $output);
            $output->writeln("");

            $args[0] = $db_ver ? \Mage_Core_Model_Resource_Setup::TYPE_DATA_UPGRADE : \Mage_Core_Model_Resource_Setup::TYPE_DATA_INSTALL;
            $output->writeln('Data Files to Run: ');
            $files_data = $this->_getAvaiableDataFilesFromResource($setup_resource, $args);
            $this->_outputFileArray($files_data, $output);            
            $output->writeln('+--------------------------------------------------+');             
            $output->writeln(''); 
        }    
    }
    
    protected function _outputFileArray($files)
    {
        $output = $this->_output;
        if(count($files) == 0)
        {
            $output->writeln('No files found');
            return;
        }
        foreach($files as $file)
        {
            $output->writeln(str_replace(\Mage::getBaseDir() . '/', '', $file['fileName']));            
        }        
    }

    /**
    * Runs a single named setup resource
    * 
    * This method nukes the global/resources node in the global config
    * and then repopulates it with **only** the $name resource. Then it
    * calls the standard Magento `applyAllUpdates` method.  
    *
    * The benifit of this approach is we don't need to recreate the entire
    * setup resource running logic ourselfs.  Yay for code reuse
    *
    * The downside is we should probably exit quickly, as anything else that
    * uses the global/resources node is going to behave weird.
    *
    * @todo Repopulate global config after running?  Non trival since setNode escapes strings
    */    
    protected function _runNamedSetupResource($name, $needs_update, $type)
    {
        $output = $this->_output;
        if(!in_array($type,array(self::TYPE_MIGRATION_STRUCTURE,self::TYPE_MIGRATION_DATA)))
        {
            throw new \Exception('Invalid Type ['.$type.']: structure, data are valid');
        }
        
        if(!array_key_Exists($name, $needs_update))
        {
            $output->writeln('<error>No updates to run for ' . $name . ', skipping </error>');
            return;
        }

        //remove all other setup resources from configuration 
        //(in memory, do not persist this to cache)        
        $real_config    = \Mage::getConfig();                        
        $resources          = $real_config->getNode('global/resources');
        foreach($resources->children() as $resource_name=>$resource)
        {
            if(!$resource->setup)
            {
                continue;
            }
            unset($resource->setup);
        }
        
        //recreate our specific node in <global><resources></resource></global>
        //allows for theoretical multiple runs
        $setup_resource         = $needs_update[$name];
        $setup_resource_config  = $this->_secondConfig->getNode('global/resources/'.$name);
        $module_name            = $setup_resource_config->setup->module;
        $class_name             = $setup_resource_config->setup->class;

        $real_config_resources = $real_config->getNode('global/resources');        
        $specific_resource  = $real_config->getNode('global/resources/' . $name);
        $setup              = $specific_resource->addChild('setup');
        if($module_name)
        {
            $setup->addChild('module',$module_name);
        }
        else
        {
            $output->writeln('<error>No module node configured for '.$name.', possible configuration error </error');
        }
        
        if($class_name)
        {
            $setup->addChild('class', $class_name);
        }


        //and finally, RUN THE UPDATES
        try
        {
            ob_start();
            if($type == self::TYPE_MIGRATION_STRUCTURE)
            {
                $this->_stashEventContext();
                \Mage_Core_Model_Resource_Setup::applyAllUpdates();
                $this->_restoreEventContext();
            }
            else if ($type == self::TYPE_MIGRATION_DATA)
            {
                \Mage_Core_Model_Resource_Setup::applyAllDataUpdates();
            }            
            $exception_output = ob_get_clean();
            print $exception_output;
        }
        catch(\Exception $e)
        {
            $exception_output = ob_get_clean();
            $this->_processExceptionDuringUpdate($e, $name, $setup_resource, $exception_output);
            return;
        }        
    }
    
    protected function _processExceptionDuringUpdate(
        $e, $name, $setup_resource, $magento_exception_output
    )
    {
        $output = $this->_output;
        $output->writeln( '<error>Magento encountered an error while running the following ' .
                          'setup resource.</error>');
        $output->writeln("\n    $name \n");
        
        $output->writeln("<error>The Good News:</error> You know the error happened, and the database   \n" .
        "information below will  help you fix this error!");
        $output->writeln("");

        $output->writeln(
        "<error>The Bad News:</error> Because Magento/MySQL can't run setup resources \n" .
        "transactionallyyour database is now in an half upgraded, invalid\n" .
        "state.  Even if you fix the error, new errors may occur due to \n" .
        "this half upgraded, invalid state.");
        $output->writeln("");        
        
        $output->writeln("What to Do: ");
        $output->writeln("1. Figure out why the error happened, and manually fix your \n   " . 
        "database and/or system so it won't happen again.");
        $output->writeln("2. Restore your database from backup.");
        $output->writeln("3. Re-run the scripts.");
        $output->writeln("");
        
        $output->writeln("Exception Message:");
        $output->writeln($e->getMessage());
        $output->writeln("");
        
        if($magento_exception_output)
        {
            $this->getHelper('dialog')->askAndValidate($output, '<question>Press Enter to view raw Magento error text:</question> ');
            $output->writeln("Magento Exception Error Text:");
            echo $magento_exception_output,"\n"; //echoing (vs. writeln) to avoid seg fault
        }                  
    }
    
    protected function _checkCacheSettings()
    {
        $output = $this->_output;
        $allTypes = \Mage::app()->useCache();
        if($allTypes['config'] !== '1')
        {
            $output->writeln('<error>ERROR: Config Cache is Disabled</error>');
            $output->writeln('This command will not run with the configuration cache disabled.');
            $output->writeln('Please change your Magento settings at System -> Cache Management');
            $output->writeln('');        
            return false;
        }    
        return true;
    }
    
    protected function _runStructureOrDataScripts($to_update, $needs_update, $type)
    {
        $output = $this->_output;
        $output->writeln('The next '.$type.' update to run is <info>' . $to_update . '</info>');
        $this->getHelper('dialog')->askAndValidate($output, 
        '<question>Press Enter to Run this update: </question>');           
        
        $start      = microtime(true);
        $this->_runNamedSetupResource($to_update, $needs_update, $type);        
        $time_ran   = microtime(true) - $start;
        $output->writeln('');
        $output->writeln(ucwords($type) . ' update <info>' . $to_update . '</info> complete.');
        $output->writeln('Ran in ' . floor($time_ran * 1000) . 'ms');                     
    }
    
    protected function _checkMagentoVersion()
    {
        $version = \Mage::getVersion();
        if(in_array($version, array('1.7.0.2','1.8.1.0')))
        {
            return true;
        }
        $this->_output->writeln('<error>ERROR: Untested with '.$version.'</error>');    
    }
    
    protected function _restoreEventContext()
    {
        $app = \Mage::app();
        $this->_setProtectedPropertyFromObjectToValue('_events', $app, $this->_eventStash);    
    }
    
    protected function _stashEventContext()
    {        
        $app = \Mage::app();
        $events = $this->_getProtectedPropertyFromObject('_events', $app);
        $this->_eventStash = $events;
        $this->_setProtectedPropertyFromObjectToValue('_events', $app, array());
    }
    
    protected function _init()
    {       
        $output = $this->_output;
        //bootstrap magento
        $this->detectMagento($this->_output);
        if(!$this->initMagento())
        {
            return;
        }
                
        //don't run if cache is off.  If cache is off that means
        //setup resource will run automagically
        if(!$this->_checkCacheSettings())
        {
            return;
        }
        
        //only run for recent versions of Magento
        //saves us the trouble of testing in < 1.7.0.1
        //and encourages people to run a  modern version
        if(!$this->_checkMagentoVersion())
        {
            return;
        }                
        
        //load a second, not cached, config.xml tree
        $this->_loadSecondConfig();
        return true;    
    }
    
    protected function _analyzeSetupResourceClasses()
    {
        $output = $this->_output;
        $this->writeSection($output, 'Analyzing Setup Resource Classes');        
        $setup_resources = $this->_getAllSetupResourceObjects();   
        $needs_update    = $this->_getAllSetupResourceObjectThatNeedUpdates($setup_resources);
                
        $output->writeln('Found <info>' . count($setup_resources) . '</info> configured setup resource(s)</info>');
        $output->writeln('Found <info>' . count($needs_update) . '</info> setup resource(s) which need an update</info>');    
        return $needs_update;
    }
    
    protected function _listDetailedUpdateInformation($needs_update)
    {
        $output = $this->_output;
        $this->getHelper('dialog')->askAndValidate($output, 
        '<question>Press Enter to View Update Information: </question>');        
        
        $this->writeSection($output, 'Detailed Update Information');        
        $this->_outputUpdateInformation($needs_update, $output);        
    }
    
    protected function _runAllStructureUpdates($needs_update)
    {
        $output = $this->_output;
        $this->writeSection($output, "Run Structure Updates");
        $output->writeln('All structure updates run before data updates.');
        $output->writeln('');
        
        $c = 1;
        $total = count($needs_update);
        foreach($needs_update as $key=>$value)
        {
            $to_update = $key;        
            $this->_runStructureOrDataScripts($to_update, $needs_update, self::TYPE_MIGRATION_STRUCTURE);
            $output->writeln("($c of $total)");
            $output->writeln('');
            $c++;
        }        
        
        $this->writeSection($output, "Run Data Updates");
        $c = 1;
        $total = count($needs_update);        
        foreach($needs_update as $key=>$value)
        {
            $to_update = $key;        
            $this->_runStructureOrDataScripts($to_update, $needs_update, self::TYPE_MIGRATION_DATA);
            $output->writeln("($c of $total)");
            $output->writeln('');
            $c++;            
        }        
    }

    
    protected function configure()
    {
        $this
            ->setName('sys:setup:incremental')
            ->setDescription('List new setup scripts to run, then runs one script')
            ->setHelp('Examines an un-cached configuration tree and determines which ' .
            'structure and data setup resource scripts need to run, and then runs them.');
    }
    
    /**
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        //sets output so we can access it from all methods
        $this->_setOutput($output);                
        if(!$this->_init())
        {
            return;
        }

        $needs_update = $this->_analyzeSetupResourceClasses();        
        if(count($needs_update) == 0)
        {
            return;
        }        
        $this->_listDetailedUpdateInformation($needs_update);            
        $this->_runAllStructureUpdates($needs_update);        
        $output->writeln('We have run all the setup resource scripts.');        
    }
}
