<?php
/**
 * Handle mapping fields calls from Ajax (gigyaFunctions.mapFields)
 * Save mapped fields map to settings
 * Delete cancelled mapped fields
 */
class Gigya_Social_MapfieldsController extends Mage_Adminhtml_Controller_Action
{
    public function IndexAction() {
        $res = array(1,2);
        $this->getResponse()->setBody($res);
    }
}