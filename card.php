<?php
	require('config.php');
	set_time_limit(0);
	
	dol_include_once('/paymentimport/lib/paymentimport.lib.php');
	dol_include_once('/core/lib/functions.lib.php');
	dol_include_once('/compta/facture/class/facture.class.php');
	dol_include_once('/compta/paiement/class/paiement.class.php');

	$langs->load('banks');
	$langs->load('payment@payment');
	
	_action();
	
	function _action()
	{
		global $user,$db,$conf,$langs;
		
		$action = GETPOST('action', 'alpha');
		
		switch ($action) {
			case 'import':
				ini_set("auto_detect_line_endings", true);
				
				$TPayment = _parseFile($conf);
				_printHeader($langs,$user);
				_printFormFile($user,$db,$conf,$langs);
				_printFormConfirm($user,$db,$conf,$langs,$TPayment);
				_printFooter();
				
				break;
			case 'import_confirm':
				_setPayment($user,$db,$conf,$langs);
				header('Location: '.dol_buildpath('/paymentimport/card.php', 2));
				exit;
				
				break;
			default:
				_printHeader($langs,$user);
				_printFormFile($user,$db,$conf,$langs);
				_printErrorFromImportConfirm();
				_printFooter();
				
				break;
		}
	}

	function _printHeader(&$langs,&$user)
	{
		llxHeader('',$langs->trans('PaymentImport'),'','');
		if(!$user->rights->facture->paiement) accessforbidden();
	}

	function _printFooter()
	{
		llxFooter();
	}

	function _printFormFile(&$user,&$db,&$conf,&$langs)
	{
		print load_fiche_titre($langs->trans('FileToImport'));
		
		print '<div class="tabBar">';
		print '<form name="import" action="' . $_SERVER["PHP_SELF"] . '" method="POST" enctype="multipart/form-data">';
		print '<input type="hidden" name="token" value="' . $_SESSION ['newtoken'] . '">';
		print '<input type="hidden" name="action" value="import">';
		print '<table class="border" width="100%">';
		print '<tr><td><label>'.$langs->trans('File').'</label></td><td><input type="file" name="file" /></td></tr>';
		print '<tr><td><label>'.$langs->trans('paymentimport_numberoflineskipped').'</label></td><td><input type="text" name="skip" size="5" value="'.(!empty($conf->global->PAYMENTIMPORT_DEFAULT_NUMBER_OF_LINE_SKIPPED) ? $conf->global->PAYMENTIMPORT_DEFAULT_NUMBER_OF_LINE_SKIPPED : 1).'" /></td></tr>';
		print '</table>';
		print '<div class="tabsAction"><div class="center divButAction"><input class="button" type="submit" value="'.$langs->trans('Load').'" /></div></div>';
		print '</form></div>';
	}
	
	function _printFormConfirm(&$user,&$db,&$conf,&$langs,&$TPayment)
	{
		global $bc;
		
		$form = new Form($db);
		$TFormCore = new TFormCore;
		print load_fiche_titre($langs->trans('Payments'));
		
		print '<div class="tabBar">';
		print '<form name="import_confirm" action="' . $_SERVER["PHP_SELF"] . '" method="POST">';
		print '<input type="hidden" name="token" value="' . $_SESSION ['newtoken'] . '">';
		print '<input type="hidden" name="action" value="import_confirm">';
		
		print '<p>';
		print '<label><b>'.$langs->trans('paymentimport_typepayment').'</b></label>&nbsp;';
		$form->select_types_paiements($conf->global->PAYMENTIMPORT_DEFAULT_REGLEMENT_CODE,'paiementcode','',2);
		print '</p>';
		
		print '<p>';
		print '<label><b>'.$langs->trans('paymentimport_bankaccount').'</b></label>&nbsp;';
		print $form->select_comptes($conf->global->PAYMENTIMPORT_DEFAULT_BANK_ACOUNT, 'fk_bank', 0);
		print '</p>';
		
		print '<table class="border" width="100%">';
		
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans("RefFacture").'</td>';
		print '<td>'.$langs->trans("Company").'</td>';
		print '<td>'.$langs->trans("CodeClient").'</td>';
		print '<td>'.$langs->trans("Rib").'</td>';
		print '<td>'.$langs->trans("AmountPayment").'</td>';
		print '<td>'.$langs->trans("DatePayment").'</td>';
		print '<td>'.$langs->trans("Num").'</td>';
		print '<td>'.$langs->trans("Note").'</td>';
		print '</tr>';
		
		$var=false;
		$i=0;
		foreach ($TPayment as $TInfo)
		{
			$var=!$var;
			print '<tr '.$bc[$var].'>';
			print '<td><input type="text" name="payment['.$i.'][facture_ref]" value="'.$TInfo['facture_ref'].'" /> - <a target="_blank" href="'.dol_buildpath('/compta/facture.php?ref='.$TInfo['facture_ref'],1).'">v</a></td>';
			print '<td>'.$TInfo['company_name'].'</td>';
			print '<td>'.$TInfo['code_client'].'</td>';
			print '<td>'.$TInfo['rib'].'</td>';
			print '<td><input type="text" name="payment['.$i.'][amount]" value="'.$TInfo['amount'].'" /></td>';
			print '<td>'.$TFormCore->calendrier('', "payment[".$i."][date_creation]", $TInfo['date_creation']).'</td>';
			print '<td><input type="text" name="payment['.$i.'][num_payment]" value="'.$TInfo['num_payment'].'" /></td>';
			print '<td><input type="text" name="payment['.$i.'][note]" value="'.$TInfo['note'].'" /></td>';
			print '</tr>';
			
			$i++;
		}
		
		print '</table>';
		print '<div class="tabsAction"><div class="center divButAction"><input class="button" type="submit" value="'.$langs->trans('CreatePayment').'" /></div></div>';
		print '</form>';
	}

	function _printErrorFromImportConfirm()
	{
		global $langs;
		
		$TFactureNotFound = $_SESSION['TFactureNotFound'];
		$TPaimentError = $_SESSION['TPaimentError'];
		$TWriteBankFail = $_SESSION['TWriteBankFail'];
		$TFactureAlreadyPaid = $_SESSION['TFactureAlreadyPaid'];
		
		if (!empty($TFactureNotFound))
		{
			print '<div class="error" style="padding:2px;">';
			foreach ($TFactureNotFound as $error) print $error.'<br />';
			print '</div>';	
		}

		if (!empty($TPaimentError))
		{
			print '<div class="error" style="margin-top:5px;padding:2px;">';
			foreach ($TPaimentError as $error) print $error.'<br />';
			print '</div>';	
		}

		if (!empty($TWriteBankFail))
		{
			print '<div class="error" style="margin-top:5px;padding:2px;">';
			foreach ($TWriteBankFail as $error) print $error.'<br />';
			print '</div>';	
		}

		if (!empty($TFactureAlreadyPaid))
		{
			print '<div class="warning" style="margin-top:5px;padding:2px;">';
			foreach ($TFactureAlreadyPaid as $warning) print $warning.'<br />';
			print '</div>';	
		}
	}

