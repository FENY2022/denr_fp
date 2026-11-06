<?php
declare(strict_types=1);

// DB-free regression for carried-forward Step 2 documents. Run:
// php tests/step2_effective_documents_test.php
$testRoot=sys_get_temp_dir().'/fpoms-effective-'.bin2hex(random_bytes(6));
define('PROJECT_ROOT',$testRoot);
mkdir($testRoot.'/storage/fp_step2/3/1',0700,true);

$fixtureRows=[];
function query_all(string $sql,array $args=[]): array {
    global $fixtureRows;
    $id=(int)($args[0]??0);
    $revision=(int)($args[1]??0);
    $rows=array_values(array_filter($fixtureRows,static fn(array $d): bool=>
        (int)$d['application_id']===$id && (int)$d['revision']<=$revision
    ));
    usort($rows,static fn(array $a,array $b): int=>
        ((int)$b['revision']<=>(int)$a['revision']) ?: ((int)$b['id']<=>(int)$a['id'])
    );
    return $rows;
}
function fpoms_file_plaintext_sha256(string $path): string {
    $hash=hash_file('sha256',$path);
    if ($hash===false) throw new RuntimeException('Unreadable test file.');
    return $hash;
}
require_once __DIR__.'/../app/permit_step2.php';
function verify_effective(bool $condition,string $name): void {
    if (!$condition) throw new RuntimeException('Step 2 effective documents: '.$name);
}
function test_document(int $id,int $revision,string $category,string $data): array {
    global $testRoot;
    $relative='fp_step2/3/1/'.$id.'.pdf';
    file_put_contents($testRoot.'/storage/'.$relative,$data);
    return [
        'id'=>$id,'application_id'=>1,'client_id'=>3,'revision'=>$revision,
        'requirement_type'=>$category,'stored_path'=>$relative,
        'sha256'=>hash('sha256',$data)
    ];
}
try {
    $fixtureRows=[
        test_document(1,1,'letter_of_intent','Original letter'),
        test_document(2,1,'annex_1a','Original annex'),
        test_document(3,2,'letter_of_intent','Corrected letter'),
    ];
    $v1=step2_effective_documents(1,1);
    verify_effective(count($v1)===2,'first revision has both documents');
    $v2=step2_effective_documents(1,2);
    verify_effective(count($v2)===2,'latest category replaces old while unchanged category carries forward');
    verify_effective(array_column($v2,'id')===[3,2],'corrected file wins and unchanged file remains');
    verify_effective(step2_effective_documents_error(1,2)===null,'two effective files pass private-storage integrity');
    verify_effective(step2_effective_documents_error(1,3)===null,'future revision with no new documents carries forward valid files');
    verify_effective(step2_effective_documents_error(2,2)!==null,'empty application cannot pass RO receipt');
    file_put_contents($testRoot.'/storage/'.$fixtureRows[1]['stored_path'],'Tampered');
    verify_effective(str_contains((string)step2_effective_documents_error(1,2),'integrity'),
        'corrupted previous-revision document blocks receipt');
    unlink($testRoot.'/storage/'.$fixtureRows[1]['stored_path']);
    verify_effective(str_contains((string)step2_effective_documents_error(1,2),'missing'),
        'missing previous-revision document blocks receipt');
    echo "Effective Step 2 document regression checks passed.\n";
} finally {
    foreach (glob($testRoot.'/storage/fp_step2/3/1/*') ?: [] as $file) @unlink($file);
    @rmdir($testRoot.'/storage/fp_step2/3/1');
    @rmdir($testRoot.'/storage/fp_step2/3');
    @rmdir($testRoot.'/storage/fp_step2');
    @rmdir($testRoot.'/storage');
    @rmdir($testRoot);
}
