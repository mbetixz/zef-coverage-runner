<?php
declare(strict_types=1);
// B0/D3: bootstrap PHPUnit kanonik = vendor/autoload.php (phpunit.xml.dist). Referensi artifact src/autoload.php dipertahankan (W6 'bootstrap test'): manual loader kini shim kompatibilitas statis, bukan jalur eksekusi.
use PHPUnit\Framework\TestCase;
use Zef\Framework\Qualification\{QualificationEvidence,QualificationGate,QualificationLedger,QualificationStatus};
final class G4_8QualificationGovernanceTest extends TestCase{
public function testMandatoryPassRequiresEvidence():void{$g=new QualificationGate('Q8.0','Contract fitness');$l=new QualificationLedger();$l->register($g);$this->expectException(InvalidArgumentException::class);$l->record('Q8.0',QualificationStatus::PASS);}
public function testMandatoryGatesPreventPromotionUntilPassed():void{$l=new QualificationLedger();$l->register(new QualificationGate('Q8.0','Contract fitness'));$l->register(new QualificationGate('Q8.5','Failure and security'));$e=new QualificationEvidence('evidence.zip',hash('sha256','evidence'),'qualified-ci','reproducible evidence');$l->record('Q8.0',QualificationStatus::PASS,[$e]);self::assertFalse($l->canPromote());$l->record('Q8.5',QualificationStatus::PASS,[$e]);self::assertTrue($l->canPromote());self::assertSame(QualificationStatus::PASS,$l->status());}
public function testMandatoryWaiverCannotPromote():void{$l=new QualificationLedger();$l->register(new QualificationGate('Q9','Endurance',true));$l->record('Q9',QualificationStatus::WAIVED,[],'Director waiver');self::assertSame(QualificationStatus::WAIVED,$l->status());self::assertFalse($l->canPromote());}}
