<?php

interface DeployMintInterface
{
    public function install();

    public function setup();

    public function getPdb();
    
    public function setPdb($pdb);

    public function adminMenu();

    public function enqueueScripts();

    public function actionIndex();

    public function actionManageBlogs();

    public function actionManageProjects();

    public function actionRevert();

    public function actionOptions();

    public function actionHelp();
}