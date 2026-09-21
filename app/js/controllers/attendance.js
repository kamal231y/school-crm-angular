/* ---------- Daily attendance marking ---------- */
angular.module('schoolApp').controller('AttendanceCtrl', ['ApiService', 'ToastService',
function (Api, Toast) {
  var vm = this;

  vm.classes  = [];
  vm.rows     = [];
  vm.loading  = false;
  vm.busy     = false;
  vm.marked   = false;
  vm.notify   = true;
  vm.maxDate  = new Date().toISOString().slice(0, 10);
  vm.filters  = { class_id: '', date: vm.maxDate };

  Api.get('classes').then(function (r) {
    vm.classes = r.data;
    if (r.data.length) {
      vm.filters.class_id = r.data[0].id;
      vm.load();
    }
  });

  vm.load = function () {
    if (!vm.filters.class_id) { return; }
    vm.loading = true;
    Api.get('attendance/sheet', vm.filters)
      .then(function (res) {
        vm.rows   = res.data.rows;
        vm.marked = res.data.marked;
      })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };

  vm.setAll = function (status) {
    vm.rows.forEach(function (r) { r.status = status; });
  };

  vm.count = function (status) {
    return vm.rows.filter(function (r) { return r.status === status; }).length;
  };

  vm.save = function () {
    if (!vm.rows.length) { return; }
    vm.busy = true;

    Api.post('attendance', {
      class_id:      vm.filters.class_id,
      date:          vm.filters.date,
      notify_absent: vm.notify,
      rows:          vm.rows.map(function (r) {
        return { student_id: r.student_id, status: r.status, remarks: r.remarks };
      })
    })
      .then(function (res) { Toast.success(res.message); vm.marked = true; })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.busy = false; });
  };
}]);

/* ---------- Attendance report (summary + monthly register) ---------- */
angular.module('schoolApp').controller('AttendanceReportCtrl', ['ApiService', 'ToastService',
function (Api, Toast) {
  var vm = this;

  var now = new Date();
  vm.classes = [];
  vm.tab     = 'summary';
  vm.loading = false;
  vm.rows    = [];
  vm.filters = {
    class_id: '',
    from: new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10),
    to:   now.toISOString().slice(0, 10)
  };
  vm.month = now.toISOString().slice(0, 7);

  Api.get('classes').then(function (r) { vm.classes = r.data; });

  vm.loadSummary = function () {
    vm.loading = true;
    Api.get('reports/attendance', vm.filters)
      .then(function (res) { vm.rows = res.data.rows; })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };
  vm.loadSummary();

  vm.loadRegister = function () {
    if (!vm.filters.class_id) {
      Toast.error('Pick a class to open the monthly register');
      return;
    }
    vm.loading = true;
    Api.get('attendance/monthly', { class_id: vm.filters.class_id, month: vm.month })
      .then(function (res) {
        vm.register = res.data;
        vm.days = [];
        for (var d = 1; d <= res.data.days_in_month; d++) { vm.days.push(d); }
      })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };

  vm.mark = function (student, day) {
    var s = student.days[day];
    return s ? s.charAt(0).toUpperCase() : '·';
  };

  vm.print = function () { window.print(); };
}]);
