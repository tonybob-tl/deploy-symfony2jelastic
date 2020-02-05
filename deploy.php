<?php

use EasyCorp\Bundle\EasyDeployBundle\Deployer\DefaultDeployer;

return new class extends DefaultDeployer
{
    //SSH PROPERTIES (they are included here as private class properties instead of in the getConfigBuilder cause rsync may need them before config is built).
    //Note: Easy Deploy does not work between Windows and Linux operating systems. Windows - Windows is fine. Linux - Linux is fine. Just no mixing. If Windows is the local operating system, you'll have to use Windows Subsystem for Linux and Ubuntu or something similar. You'll need to build the environment there first and run deploys from there. 
    //Note: if problems with ssh or rsync, make sure port 22 is open. Many firewalls block it.
    //These class variables are necessary whenever the sprintf and rsync command is used.
    //If these class variables are also included in the server properties arrays for each server in getConfigBuilder, then they can be accessed using the {{ property }} call. Those calls are used for the runLocal runRemote commands.
    //Example sprintf used to move a composer.phar (just example of file move): 
    //$this->runLocal(sprintf("rsync -v -e 'ssh -p %s' --progress %s/composer.phar %s@%s:%s/composer", $this->SSHPort, $this->localComposerPharDir,$this->SSHUser,$this->SSHHost,$this->DeployDir));
    
    //Username for Jelastic is user number (first five) followed by the apache node number (four numbers) with dash inbetween. This calls the node directly.
    private $SSHUser="#####-####";
    private $SSHHost="gate.jelastic.eapps.com";
    //If port is not in default 22, use this to specify it - put in rsync's -e parameter, then ssh's -p parameter in the e's quotes
    private $SSHPort="3022";
    //If private key is not in default ~/.ssh folder, use this to specify it's path - put in rsync's -e parameter, then ssh's -i parameter in the e's quotes
    //Make sure you load the public key on Jelastic via its dashboard interface. 
    //A good set of instructions can be found at: https://confluence.atlassian.com/bitbucket/set-up-an-ssh-key-728138079.html and https://confluence.atlassian.com/bitbucket/set-up-additional-ssh-keys-271943168.html (if you want to make a bunch of keys for different servers). 
    private $SSHKeyPath="/home/username/.ssh/id_rsa"; //This probably isn't needed as .ssh/id_rsa is usually default
    private $useSSHAgentForwarding=false; //False here because a SSH key needs to be made on Jelastic and used to access Bitbucket repo (can't forward the keys from the local computer). See https://github.com/EasyCorp/easy-deploy-bundle/blob/master/doc/tutorials/remote-code-cloning.md#deploy-keys and https://docs.jelastic.com/git-ssh
    
    //ADD MORE Properties if more servers are involved and use to make another server below in getConfigBuilder.
    
    //REMOTE DIRECTORIES
    private $DeployDir="/var/www/webroot";//This is probably the directory that you need to load all the folders in. Public directory is empty. You need to configure httpd.conf for Apache node on Jelastic so that it points to the /var/www/webroot/current folder. See Readme.
    private $LogDirectoryPath="/var/www/webroot/apachelogs"; //This is the path name for the logging directory. !IMPORTANT: This path name needs to match the directory indicated for the ErrorLog and CustomLog indicated in the VirtualHost of the httpd.conf.
    
    //GIT REPOSITORIES
    private $repositoryURL="git@bitbucket.org:yourrepo.git";//SSH address from bitbucket or github account. git and clone commands are added auto.
    private $repoBranch="master";
    
    //SYMFONY AS SUBPROJECT OR IN SUBDIRECTORY Of GIT REPO
    //If your symfony project is in a subdirectory of your git repository, indicate true for $isSymfonySubDirectory and add relative path
    //When true, after the git clone, this deployer will get rid of unneeded git folders and leave only symfony project as a root
    private $isSymfonySubDirectory =false;
    //This is relative to git repository root. Include leading slash but don't include trailing slash in the path...
    private $relativePathToSymfonyProject="/yoursymfonyprojectfolder";
    
    //COMPOSER
    private $remoteComposerPath="composer"; //Just the command cause it is automatically included globally when you install php/apache environment on Jelastic
    private $updateRemoteComposer=true; //Do you want to do a composer self update before doing the install?
    private $composerInstallFlags="--no-dev --prefer-dist --no-interaction --no-scripts --verbose"; //Flags used when running composer install
    private $composerOptimizeFlags="--optimize --verbose"; //Flags used when running composer optimization
    
    //WEBPACK ENCORE DEPLOY LOCAL TO REMOTE 
    //Note: this requires rsync to transfer the built files.
    //Requires yarn to be installed with webpack encore for Symfony
    private $isWebPackProject=false;
    private $localBuildFileToXFer="/home/username/path/to/local/public/build";
    //This route is relative to the repo, release folder, and current folder on the remote server. During the deploy process, it will be added after repo is updated, but before release is copied into current     .
    //Include leading slash but don't include trailing slash in the path...
    private $remoteRelativePathToBuildDir="/public/build";
   
    public function configure()
    {
        return $this->getConfigBuilder()
            // SSH connection string to connect to the remote server (format: user@host-or-IP:port-number)
            ->server(sprintf('%s@%s -p %s', $this->SSHUser, $this->SSHHost, $this->SSHPort),['app'], [
                'SSHUSER' => $this->SSHUser,
                'SSHHost' => $this->SSHHost,
                'SSHPort' => $this->SSHPort,
                'SSHKeyPath' => $this->SSHKeyPath,
                'remoteRelativePathToBuildDir' => isset($this->remoteRelativePathToBuildDir)?$this->remoteRelativePathToBuildDir : "",
                'relativePathToSymfonyProject' => isset($this->isSymfonySubDirectory)?$this->relativePathToSymfonyProject : "",
                'logDirectoryPath' => $this->LogDirectoryPath
                ])
                
            // the absolute path of the remote server directory where the project is deployed
            ->deployDir($this->DeployDir)
            // the URL of the Git repository where the project code is hosted
            ->repositoryUrl($this->repositoryURL)
            // the repository branch to deploy
            ->repositoryBranch($this->repoBranch)
            //Using private/public ssh keys between Jelastic and Bitbucket to download
            ->useSshAgentForwarding($this->useSSHAgentForwarding)
            //composer.phar needs to be included in root of project
            //Note: for Jelastic, composer comes with PHP Apache environment. It is installed globally. 
            ->remoteComposerBinaryPath($this->remoteComposerPath)
            //Composer.phar is updated to latest version
            ->updateRemoteComposerBinary($this->updateRemoteComposer)
            //Composer options
            ->composerInstallFlags($this->composerInstallFlags)
            ->composerOptimizeFlags($this->composerOptimizeFlags)
        ;
    }

    // run some local or remote commands before the deployment is started
    public function beforeStartingDeploy()
    {
        if ($this->isWebPackProject){
            //build webpack encore production
            $this->log("Doing local Webpack Encore production build.");
            //Make sure you add: process.env.NODE_ENV = Encore.isProduction() ? 'production' : 'development'; 
            //Right after require's in webpack.config.js - it will read Encore state and set NODE_ENV to match
            $this->runLocal('NODE_ENV=production yarn encore production --progress');
            $this->log("Webpack Encore production build complete.");
        }
        // $this->runLocal('./vendor/bin/simple-phpunit');
    }
    
    public function beforeUpdating(){
    }
    
    public function beforePreparing(){
        
        //Scheduleamap specific rearranging (to get rid of all the unnecessary subprojects)
        //Includes the .env movement
        if ($this->isSymfonySubDirectory){
            $this->runRemote('if [ -d {{ deploy_dir }}/repo{{ relativePathToSymfonyProject }} ]; then mkdir {{ deploy_dir }}/purgatory && cp -RPp {{ deploy_dir }}/repo{{ relativePathToSymfonyProject }}/. {{ deploy_dir }}/purgatory && rm -r {{ deploy_dir }}/repo/* && rm -r {{ project_dir }}/* && cp -RPp {{ deploy_dir }}/purgatory/. {{ deploy_dir }}/repo && cp -RPp {{ deploy_dir }}/purgatory/* {{ project_dir }} && cp -RPp {{ deploy_dir }}/purgatory/.env {{ project_dir }}/ && rm -r {{ deploy_dir }}/purgatory; fi');

            $this->runRemote('cd {{ deploy_dir }}/repo && ls -a');
            $this->runRemote('ls -a');
        } else {
            $this->runRemote('cp -RPp {{ deploy_dir }}/repo/.env {{ project_dir }}/');
        }
        
        //Add Webpack Encore Production build for front end...
        if ($this->isWebPackProject){
            //Copy from local to repo
            $this->log(sprintf("Copying Webpack Encore Files from %s to %s/repo%s", $this->localBuildFileToXFer, $this->DeployDir, $this->remoteRelativePathToBuildDir));
            $this->runRemote('mkdir -p {{ deploy_dir }}/repo{{ remoteRelativePathToBuildDir }}');
            $this->runLocal(sprintf("rsync -r -v -e 'ssh -p %s' --progress %s/* %s@%s:%s/repo%s/", $this->SSHPort, $this->localBuildFileToXFer, $this->SSHUser, $this->SSHHost, $this->DeployDir, $this->remoteRelativePathToBuildDir));
            $this->log("rsync finished...");
            $this->runRemote(sprintf('cd %s/repo%s && ls -a', $this->DeployDir, $this->remoteRelativePathToBuildDir));
            //Then copy it to project directory
            $this->runRemote('mkdir -p {{ project_dir }}{{ remoteRelativePathToBuildDir }} && cp -RPp {{ deploy_dir }}/repo{{ remoteRelativePathToBuildDir }}/* {{ project_dir }}{{ remoteRelativePathToBuildDir }}/');
            $this->log("copy from repo to project finished...");
            $this->runRemote('cd {{ project_dir }}{{ remoteRelativePathToBuildDir }} && ls -a');
            $this->log("Webpack Encore files have been transfered.");
            //The copy/symlink to current folder should be automatic after this.
            
        } 
        //Add the log file directory 
        $this->runRemote('mkdir -p {{'logDirectoryPath'}});
        
    }
    
    public function beforeInstallDependencies(){
        
    }
    
    public function beforeInstallWebAssets(){
    }
    
    public function beforeOptimizing()
    {
    }

    public function beforePublishing()
    {
        $this->log('<h2>Composer dump-env prod && composer symfony/apache-pack</h2>');
        //Run composer dump-env prod to optimize reading the variables in .env
        $this->runRemote(sprintf('%s dump-env prod --no-interaction', $this->remoteComposerPath));
        $this->runRemote('ls -a');
        
    }

    public function beforeRollingBack()
    {
    }

    // run some local or remote commands after the deployment is finished
    public function beforeFinishingDeploy()
    {
        //Remove the .env cause environmental variables need to be put into the system.
        
        $this->runRemote('rm {{ project_dir }}/.env.* ');
        $this->runRemote('rm {{ deploy_dir }}/repo/.env.* ');
        
        //Make the .htaccess file viable
        $this->log("<h2>Making .htaccess file viable. Before doing the deploy, MUST RUN composer require symfont/apache-pack locally and make it unhidden locally by getting rid of the '.' and then pushing to repo (i.e., manually change .htaccess to htaccess locally and doing a git push)</h2>");
        $this->runRemote('mv {{ deploy_dir }}/current/public/htaccess {{ deploy_dir }}/current/public/.htaccess');
        // $this->runRemote('{{ console_bin }} app:my-task-name');
        // $this->runLocal('say "The deployment has finished."');
    }
    
    public function beforeCancelingDeploy(){
        $this->runRemote('if [ -d {{ deploy_dir }}/purgatory ]; then rm -r {{ deploy_dir }}/purgatory; fi'); 
    }
    
    public function beforeStartingRollback(){
    }
    
    public function beforeCancelingRollback(){
    }
    
    public function beforeFinishingRollback(){
        
        $this->runRemote('if [ -d {{ deploy_dir }}/purgatory ]; then rm -r {{ deploy_dir }}/purgatory; fi'); 
    }
};
