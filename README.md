# deploy-symfony2jelastic
EasyDeployBundle customized default deployer to deploy Symfony 4+ projects to Jelastic PAAS.

Please see Instructions 

Note: EasyDeployBundle does not handle deploys from local Windows operating systems to Linux Server systems. The remote commands will fail because EasyDeployBundle reads what the local operating system is and uses those commands and directory syntax when creating its remote commands. This is the case for Jelasic's PHP/Apache environment. Therefore, if you operate on a windows system, you'll need to install Windows Subsystem for Linux or WSL and then Ubuntu or similar. Instructions exist all over the place. You'll need to clone your project using Ubuntu bash (or similar) and use composer to build it locally.  
