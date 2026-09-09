<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Zef\Framework\Qualification\{QualificationEvidence,QualificationGate,QualificationLedger,QualificationStatus};
$l=new QualificationLedger();$l->register(new QualificationGate('Q8.0','Integrated contract fitness'));$l->register(new QualificationGate('Q8.5','Failure, security and resource qualification'));$e=new QualificationEvidence('evidence.zip',hash('sha256','w6'),'native-smoke','deterministic qualification evidence');$l->record('Q8.0',QualificationStatus::PASS,[$e]);if($l->canPromote())throw new RuntimeException('Promotion opened before all mandatory gates passed.');$l->record('Q8.5',QualificationStatus::PASS,[$e]);if(!$l->canPromote()||$l->status()!==QualificationStatus::PASS)throw new RuntimeException('Qualification ledger did not close deterministically.');echo "W6 qualification governance smoke: PASS\n";
