(() => {
    const page = window.studentPageData;
    if (!page) return;
    const $ = (id) => document.getElementById(id);
    const modal = $('studentModal');
    const importModal = $('studentImportModal');
    const sectionSelect = $('studentSection');
    const notice = $('studentNotice');
    let importFile = null;
    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    window.filterStudentSections = (selected = '') => {
        const gradeId = $('studentGrade').value;
        const choices = page.sections.filter((section) => Number(section.grade_level_id) === Number(gradeId) && Number(section.school_year_id) === Number(page.schoolYearId));
        sectionSelect.innerHTML = '<option value="">Unassigned</option>' + choices.map((section) => `<option value="${section.id}">${escapeHtml(section.name)}</option>`).join('');
        sectionSelect.value = String(selected || '');
    };
    window.openStudentForm = (student = {}) => {
        $('studentFormError').textContent = '';
        $('studentModalTitle').textContent = student.id ? 'Edit Student' : 'Add Student';
        $('studentId').value = student.id || '';
        $('studentLrn').value = student.lrn || '';
        $('studentName').value = student.name || '';
        $('studentSex').value = student.sex || '';
        $('studentGrade').value = student.grade || '';
        filterStudentSections(student.section || '');
        modal.style.display = '';
    };
    window.closeStudentForm = () => { modal.style.display = 'none'; };
    window.editStudent = (button) => {
        const row = button.closest('tr');
        openStudentForm({id:row.dataset.studentId,lrn:row.dataset.lrn,name:row.dataset.name,sex:row.dataset.sex,grade:row.dataset.grade,section:row.dataset.section});
    };
    window.saveStudent = async () => {
        const payload = {id:$('studentId').value || null,lrn:$('studentLrn').value.trim(),name:$('studentName').value.trim(),sex:$('studentSex').value,grade_level_id:$('studentGrade').value || null,section_id:sectionSelect.value || null,school_year_id:page.schoolYearId,csrf_token:page.csrf};
        const response = await fetch('api/admin_save_student.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':page.csrf},body:JSON.stringify(payload)});
        const result = await response.json();
        if (!response.ok || result.error) {$('studentFormError').textContent=result.error || 'Could not save student.';return;}
        window.location.reload();
    };
    window.openStudentImport = () => {
        importFile = null;
        $('studentImportFile').value = '';
        $('studentImportFileName').textContent = '';
        $('importError').textContent = '';
        $('confirmStudentImport').disabled = true;
        if (page.isAdmin) { $('importGrade').value = ''; filterImportSections(); }
        else {
            const sections = page.sections.filter((section)=>Number(section.school_year_id)===Number(page.schoolYearId));
            $('importSection').innerHTML = '<option value="">Select section</option>' + sections.map((section)=>`<option value="${section.id}">Grade ${section.level} · ${escapeHtml(section.name)}</option>`).join('');
        }
        $('importFileStep').style.display = 'none';
        importModal.style.display = '';
    };
    window.importStudents = (input) => {
        if (!input.files.length) return;
        importFile = input.files[0];
        input.value = '';
        $('studentImportFileName').textContent = importFile.name;
        $('importError').textContent = '';
        $('confirmStudentImport').disabled = false;
    };
    window.updateImportFileStep = () => {
        importFile = null;
        $('studentImportFile').value = '';
        $('studentImportFileName').textContent = '';
        $('confirmStudentImport').disabled = true;
        $('importFileStep').style.display = $('importSection').value ? '' : 'none';
    };
    window.filterImportSections = () => {
        const gradeId = $('importGrade').value;
        const sections = page.sections.filter((section)=>Number(section.grade_level_id)===Number(gradeId)&&Number(section.school_year_id)===Number(page.schoolYearId));
        $('importSection').innerHTML = '<option value="">Select section</option>' + sections.map((section)=>`<option value="${section.id}">${escapeHtml(section.name)}</option>`).join('');
        $('importSection').disabled = !gradeId;
        updateImportFileStep();
    };
    window.cancelStudentImport = () => { importFile=null; importModal.style.display='none'; $('studentImportFile').value=''; };
    window.confirmStudentImport = async () => {
        if (!importFile) return;
        const gradeId = page.isAdmin ? $('importGrade').value : '';
        const sectionId = $('importSection').value;
        if (!sectionId) {$('importError').textContent='Select a section before importing.';return;}
        const selectedSection = page.sections.find((section) => String(section.id) === String(sectionId));
        const form = new FormData();
        form.append('file',importFile); form.append('school_year_id',page.schoolYearId); form.append('csrf_token',page.csrf);
        if (selectedSection) form.append('grade_level_id',gradeId || selectedSection.grade_level_id);
        form.append('section_id',sectionId);
        const response=await fetch('api/import_students.php',{method:'POST',headers:{'X-CSRF-Token':page.csrf},body:form});
        const result=await response.json();
        if(!response.ok||result.error){$('importError').textContent=result.error||'Import failed.';return;}
        const errors=(result.errors||[]).join(' ');
        showNotice(`Imported ${result.imported} student row(s). ${result.error_count ? `${result.error_count} row(s) were skipped. ${errors}` : ''}`,'success');
        cancelStudentImport();
        if(result.imported) window.setTimeout(()=>window.location.reload(),1200);
    };
    const gradeFilter = $('studentGradeFilter');
    const sectionFilter = $('studentSectionFilter');
    const searchFilter = $('studentSearchFilter');
    const studentRows = () => [...document.querySelectorAll('#studentsBody tr[data-student-id]')];
    function refreshSectionFilter() {
        const current = sectionFilter.value;
        const grade = gradeFilter.value;
        const sections = page.sections.filter((section) => Number(section.school_year_id) === Number(page.schoolYearId)
            && (!grade || Number(section.level) === Number(grade)));
        const seen = new Set();
        const choices = sections.filter((section) => {
            if (seen.has(Number(section.id))) return false;
            seen.add(Number(section.id));
            return true;
        });
        sectionFilter.innerHTML = '<option value="">All sections</option>'
            + choices.map((section) => `<option value="${section.id}">${escapeHtml(section.name)}</option>`).join('');
        sectionFilter.value = choices.some((section) => String(section.id) === current) ? current : '';
    }
    window.filterStudents = () => {
        const grade = gradeFilter.value;
        const section = sectionFilter.value;
        const query = searchFilter.value.trim().toLocaleLowerCase();
        const rows = studentRows();
        let shown = 0;
        rows.forEach((row) => {
            const matchesGrade = !grade || row.dataset.gradeLevel === grade;
            const matchesSection = !section || row.dataset.section === section;
            const matchesSearch = !query || row.dataset.name.toLocaleLowerCase().includes(query)
                || row.dataset.lrn.toLocaleLowerCase().includes(query);
            const visible = matchesGrade && matchesSection && matchesSearch;
            row.style.display = visible ? '' : 'none';
            if (visible) shown++;
        });
        $('studentsEmptyState').style.display = rows.length && shown === 0 ? '' : 'none';
        window.MpsTablePagination?.reset($('studentsTable'));
    };
    gradeFilter.addEventListener('change', () => { refreshSectionFilter(); window.filterStudents(); });
    sectionFilter.addEventListener('change', window.filterStudents);
    searchFilter.addEventListener('input', window.filterStudents);
    $('clearStudentFilters').addEventListener('click', () => {
        gradeFilter.value = '';
        searchFilter.value = '';
        refreshSectionFilter();
        window.filterStudents();
    });
    refreshSectionFilter();
    window.filterStudents();
    function showNotice(message,type){notice.className=`alert alert-${type}`;notice.textContent=message;notice.style.display='';}
})();
