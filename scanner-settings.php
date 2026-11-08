<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$sess = require_login('teacher');
$pdo = get_pdo();
$teacherId = (int)$sess['user_id'];
$stmt = $pdo->prepare("SELECT a.id, a.title, a.total_items, s.name AS subject_name, a.status, COALESCE(a.is_shared,0) AS is_shared
    FROM assessments a JOIN subjects s ON s.id=a.subject_id
    LEFT JOIN teacher_assessment_encodings tae ON tae.assessment_id=a.id AND tae.teacher_id=?
    WHERE ((COALESCE(a.is_shared,0)=0 AND a.teacher_id=?) OR (COALESCE(a.is_shared,0)=1 AND tae.id IS NOT NULL))
      AND a.status NOT IN ('submitted','approved')
      AND COALESCE(tae.status,'draft') NOT IN ('submitted','approved')
    ORDER BY s.name, a.title");
$stmt->execute([$teacherId, $teacherId]);
$assessments = $stmt->fetchAll();
$csrf = csrf_token();
?>
<?php
$pageTitle = 'Scanner Setup';
$activeNavPage = 'scanner-settings';
$layoutBodyClass = 'scanner-settings-page layout-fixed sidebar-expand-lg bg-body-tertiary';
require __DIR__ . '/includes/master_layout_start.php';
?>
  <div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h1 class="h3 mb-1">MPS Scanner setup</h1><div class="text-secondary">Teacher: <?= h($sess['display']) ?></div></div><a class="btn btn-outline-secondary" href="<?= BASE_URL ?>teacher-dashboard.php">Back to assessments</a></div>
  <section class="card mb-3">
    <div class="card-header"><h2 class="card-title">Scanner sign-in and audit</h2></div>
    <div class="card-body"><p class="mb-0">Teachers sign into the Scanner PWA with their MPS account. MPS issues an expiring scanner session automatically. Review submissions and rejected attempts in the <a href="<?= BASE_URL ?>scanner-audit.php">scan audit trail</a>.</p></div>
  </section>
<section class="card">
    <div class="card-header"><h2 class="card-title">Assessment answer keys</h2></div>
    <div class="card-body">
    <p>Enter the correct A–D choice for every item in order, separated by commas or spaces. These keys are used by MPS to calculate scanner scores.</p>
    <label class="form-label" for="assessment">Assessment</label>
    <select class="form-select mb-3" id="assessment"><option value="">Select an assessment</option>
      <?php foreach ($assessments as $assessment): ?>
      <option value="<?= (int)$assessment['id'] ?>" data-total="<?= (int)$assessment['total_items'] ?>"><?= h($assessment['subject_name'] . ' — ' . $assessment['title'] . ' (' . $assessment['total_items'] . ' items)') ?></option>
      <?php endforeach; ?>
    </select>
    <label class="form-label" for="answerKey">Answer key</label>
    <textarea class="form-control mb-3" id="answerKey" rows="3" placeholder="A, C, D, B, …"></textarea>
    <div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-secondary" id="loadKey">Load key</button><button class="btn btn-primary" id="saveKey">Save answer key</button></div>
    <div id="keyMessage" class="mt-3" hidden></div>
    </div>
  </section>
<script>
const apiRoot = <?= json_encode(rtrim(BASE_URL, '/') . '/api/') ?>;
const csrf = <?= json_encode($csrf) ?>;
async function request(path, options={}) {
  const response = await fetch(apiRoot + path, { ...options, headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN':csrf, ...(options.headers||{}) } });
  const body = await response.json().catch(()=>({}));
  if (!response.ok) throw new Error(body.error || `Request failed (${response.status})`);
  return body;
}
function showMessage(el,text,isError=false){el.hidden=false;el.className=`alert ${isError?'alert-danger':'alert-success'} mt-3`;el.textContent=text;}
const assessment=document.getElementById('assessment');
const answerKey=document.getElementById('answerKey');
const keyMessage=document.getElementById('keyMessage');
document.getElementById('loadKey').onclick=async()=>{try{if(!assessment.value)throw new Error('Choose an assessment first.');const data=await request(`scanner_answer_key.php?assessment_id=${encodeURIComponent(assessment.value)}`,{method:'GET'});answerKey.value=data.answer_key.map(x=>x.correct_choice).join(', ');showMessage(keyMessage,`${data.answer_key.length} of ${data.total_items} answers loaded.`);}catch(e){showMessage(keyMessage,e.message,true);}};
document.getElementById('saveKey').onclick=async()=>{try{if(!assessment.value)throw new Error('Choose an assessment first.');const option=assessment.selectedOptions[0];const choices=answerKey.value.trim().split(/[\s,;]+/).filter(Boolean).map(x=>x.toUpperCase());const total=Number(option.dataset.total);if(choices.length!==total)throw new Error(`Enter exactly ${total} A–D choices; found ${choices.length}.`);if(choices.some(x=>!['A','B','C','D'].includes(x)))throw new Error('Use only A, B, C, or D.');await request('scanner_answer_key.php',{method:'PUT',body:JSON.stringify({assessment_id:Number(assessment.value),answer_key:choices})});showMessage(keyMessage,`Saved ${total} answers. Scanner submissions will use this key.`);}catch(e){showMessage(keyMessage,e.message,true);}};
</script>
<?php require __DIR__ . '/includes/master_layout_end.php'; ?>



