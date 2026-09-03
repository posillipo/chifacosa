<?php
session_start();
// La scelta del tipo di account è stata unificata dentro onboarding_setup.php (un'unica
// schermata dopo la prima registrazione, invece di due passaggi separati) — questa pagina resta
// solo per non rompere eventuali link già in giro.
header('Location: /onboarding_setup.php');
exit;
