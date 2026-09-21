/* ---------- Student register report ---------- */
angular.module('schoolApp').controller('StudentReportCtrl', ['ApiService', 'ToastService', '$window',
function (Api, Toast, $window) {
  var vm = this;

  vm.classes = [];
  vm.filters = { class_id: '', gender: '', status: 'active', from: '', to: '' };

  Api.get('classes').then(function (r) { vm.classes = r.data; });

  vm.load = function () {
    vm.loading = true;
    Api.get('reports/students', vm.filters)
      .then(function (res) {
        vm.rows        = res.data.rows;
        vm.count       = res.data.count;
        vm.generatedAt = res.data.generated_at;
      })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };
  vm.load();

  vm.print = function () { $window.print(); };

  vm.exportCsv = function () {
    exportCsv(vm.rows, [
      ['admission_no', 'Admission No'], ['name', 'Name'], ['class_name', 'Class'],
      ['gender', 'Gender'], ['father_name', 'Father'], ['guardian_phone', 'Phone'],
      ['admission_date', 'Admitted On'], ['attendance_pct', 'Attendance %'], ['fee_due', 'Fee Due']
    ], 'student-report.csv');
  };
}]);

/* ---------- Fee collection report ---------- */
angular.module('schoolApp').controller('FeeReportCtrl', ['ApiService', 'ToastService', '$window',
function (Api, Toast, $window) {
  var vm = this;
  var now = new Date();

  vm.filters = {
    from: new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10),
    to:   now.toISOString().slice(0, 10)
  };

  vm.load = function () {
    vm.loading = true;
    Api.get('reports/fees', vm.filters)
      .then(function (res) {
        vm.rows   = res.data.rows;
        vm.total  = res.data.total;
        vm.byMode = res.data.by_mode;
      })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };
  vm.load();

  vm.print = function () { $window.print(); };

  vm.exportCsv = function () {
    exportCsv(vm.rows, [
      ['receipt_no', 'Receipt'], ['paid_on', 'Date'], ['student_name', 'Student'],
      ['class_name', 'Class'], ['title', 'Fee Head'], ['mode', 'Mode'], ['amount', 'Amount']
    ], 'fee-collection.csv');
  };
}]);

/* Shared CSV helper — browser me hi file bana deta hai, server call nahi. */
function exportCsv(rows, columns, filename) {
  if (!rows || !rows.length) { return; }
  var head = columns.map(function (c) { return '"' + c[1] + '"'; }).join(',');
  var body = rows.map(function (r) {
    return columns.map(function (c) {
      var v = r[c[0]] === null || r[c[0]] === undefined ? '' : String(r[c[0]]);
      return '"' + v.replace(/"/g, '""') + '"';
    }).join(',');
  }).join('\n');

  var blob = new Blob([head + '\n' + body], { type: 'text/csv;charset=utf-8;' });
  var link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
