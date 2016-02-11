<?php
/* <one line to give the program's name and a brief idea of what it does.>
 * Copyright (C) 2015 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *	\file		lib/paymentimport.lib.php
 *	\ingroup	paymentimport
 *	\brief		This file is an example module library
 *				Put some comments here
 */

function paymentimportAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load("paymentimport@paymentimport");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/paymentimport/admin/paymentimport_setup.php", 1);
    $head[$h][1] = $langs->trans("Parameters");
    $head[$h][2] = 'settings';
    $h++;
    $head[$h][0] = dol_buildpath("/paymentimport/admin/paymentimport_about.php", 1);
    $head[$h][1] = $langs->trans("About");
    $head[$h][2] = 'about';
    $h++;

    // Show more tabs from modules
    // Entries must be declared in modules descriptor with line
    //$this->tabs = array(
    //	'entity:+tabname:Title:@paymentimport:/paymentimport/mypage.php?id=__ID__'
    //); // to add new tab
    //$this->tabs = array(
    //	'entity:-tabname:Title:@paymentimport:/paymentimport/mypage.php?id=__ID__'
    //); // to remove a tab
    complete_head_from_modules($conf, $langs, $object, $head, $h, 'paymentimport');

    return $head;
}

function _parseFile(&$conf)
{
	$TPayment = array();
	$skip = GETPOST('skip', 'int');
	$file = $_FILES['file'];
	
	/*if ($file['type'] == 'application/csv' || $file['type'] == 'text/csv')
	{*/
		$TPayment = array();
		$handle = fopen($file['tmp_name'], 'r');
		$i=0;
		while ($line = fgetcsv($handle, 4096, ';'))
		{
			$i++;
			if ($i <= $skip) continue;
			if (array(null) === $line) continue; // Skip blank line
			
			if (mb_detect_encoding($line[1], 'UTF-8', true) != 'UTF-8') $line[1] = utf8_encode($line[1]);
			if (mb_detect_encoding($line[11], 'UTF-8', true) != 'UTF-8') $line[11] = utf8_encode($line[11]);
			
			$amount = price2num($line[4]);
			$amount = preg_replace('/[^0-9,\.-]/', '', $amount);
			
			$TPayment[] = array(
				'code_client' => !empty($conf->global->PAYMENTIMPORT_CODECLIENT_SUBSTR) ? substr($line[0], $conf->global->PAYMENTIMPORT_CODECLIENT_SUBSTR-1) : $line[0]
				,'company_name' => $line[1]
				,'facture_ref' => $line[7]
				,'rib' => $line[3]
				,'amount' => $amount
				,'date_creation' => $line[8]
				,'num_payment' => $line[10]
				,'note' => $line[11]
			);
			
		}
		fclose($handle);	
	//}

	return $TPayment;
}

function _setPayment($user,$db,$conf,$langs)
{
	$TPayment = GETPOST('payment', 'array');
	$fk_bank = GETPOST('fk_bank', 'int');
	if (empty($fk_bank)) {
		if (function_exists('setEventMessages')) setEventMessages($langs->transnoentitiesnoconv('ErrorPaymentImportNoBankSelected'), array(), 'errors');
		else setEventMessage($langs->transnoentitiesnoconv('ErrorPaymentImportNoBankSelected'), 'errors');
		
		return;
	}
	
	$mode_reglement = GETPOST('paiementcode', 'alpha');
	
	$TFactureNotFound = $TPaimentError = $TWriteBankFail = array();
	$nb_facture_not_found = $nb_payment = $nb_writebank = 0;
	foreach ($TPayment as $TInfoPayment)
	{
		$facture_ref = trim($TInfoPayment['facture_ref']);
		$rib = trim($TInfoPayment['rib']);
		$amount = price2num($TInfoPayment['amount']);
		$date_creation = trim($TInfoPayment['date_creation']);
		$num_payment = trim($TInfoPayment['num_payment']);
		$note = trim($TInfoPayment['note']);
		
		if ($amount <= 0) continue;
		
		$facture = new Facture($db);
		if ($facture->fetch(null, $facture_ref) > 0)
		{
			if (!empty($conf->global->PAYMENTIMPORT_FORCE_DATE_TODAY)) $datepaye = dol_mktime(date('H'), date('m'), date('s'), date('m'), date('d'), date('Y'));
			else 
			{
				$date_creation = explode('/', $date_creation);
				$datepaye = dol_mktime(12, 0, 0, $date_creation[1], $date_creation[0], $date_creation[2]);
			}
			
			if (empty($mode_reglement)) $paiementcode = $facture->mode_reglement_code;
			else $paiementcode = $mode_reglement;
			
			// Creation of payment line
		    $paiement = new Paiement($db);
		    $paiement->datepaye     = $datepaye;
		    $paiement->amounts      = array($facture->id => $amount);   // Array with all payments dispatching
		    $paiement->paiementid   = dol_getIdFromCode($db,$paiementcode,'c_paiement');
		    $paiement->num_paiement = $num_payment;
		    $paiement->note         = $note;
			
	    	$paiement_id = $paiement->create($user, 1);
	    	if ($paiement_id > 0)
	        {
	        	$nb_payment++;
				
				$label='(CustomerInvoicePayment)';
		        $result=$paiement->addPaymentToBank($user,'payment',$label,$fk_bank,'','');
				
				if ($result > 0) $nb_writebank++;
				else $TWriteBankFail[] = $langs->transnoentitiesnoconv('paymentimport_errorwritebank', $facture_ref);
				
	        }
			else
			{
				$TPaimentError[] = $langs->transnoentitiesnoconv('paymentimport_errorpayment', $facture_ref, $rib, $amount);
			}
		    
		}
		else {
			$TFactureNotFound[] = $langs->transnoentitiesnoconv('paymentimport_errorfactnotfound', $facture_ref);
			$nb_facture_not_found++;
		}
	}
	
	if ($nb_facture_not_found > 0) 
	{
		if (function_exists('setEventMessages')) setEventMessages($langs->trans('paymentimport_nb_facture_not_found', $nb_facture_not_found), array(), 'errors');
		else setEventMessage($langs->trans('paymentimport_nb_facture_not_found', $nb_facture_not_found), 'errors');
	}
	if ($nb_payment > 0) 
	{
		if (function_exists('setEventMessages')) setEventMessages($langs->trans('paymentimport_nb_payment', $nb_payment), array());
		else setEventMessage($langs->trans('paymentimport_nb_payment', $nb_payment));
	}
	if ($nb_writebank > 0)
	{
		if (function_exists('setEventMessages')) setEventMessages($langs->trans('paymentimport_nb_writebank', $nb_writebank), array());
		else setEventMessage($langs->trans('paymentimport_nb_writebank', $nb_writebank));
	}
	
	$_SESSION['TFactureNotFound'] = $TFactureNotFound;
	$_SESSION['TPaimentError'] = $TPaimentError;
	$_SESSION['TWriteBankFail'] = $TWriteBankFail;
}
