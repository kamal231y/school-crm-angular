angular.module('schoolApp').controller('SmsCtrl', ['ApiService', 'ToastService', 'AuthService',
function (Api, Toast, Auth) {
  var vm = this;

  vm.tab      = 'compose';
  vm.classes  = [];
  vm.logs     = [];
  vm.isAdmin  = Auth.is('admin');
  vm.compose  = { audience: 'class', class_id: '', message: '', student_ids: [] };
  vm.picked   = [];

  Api.get('classes').then(function (r) { vm.classes = r.data; });

  vm.chars = function () { return (vm.compose.message || '').length; };
  vm.parts = function () { return Math.ceil(vm.chars() / 160) || 0; };

  vm.searchStudents = function () {
    if (!vm.query || vm.query.length < 2) { vm.results = []; return; }
    Api.get('students', { search: vm.query, per_page: 8 })
      .then(function (r) { vm.results = r.data; });
  };

  vm.addStudent = function (s) {
    if (vm.picked.some(function (p) { return p.id === s.id; })) { return; }
    vm.picked.push(s);
    vm.compose.student_ids.push(s.id);
    vm.query = '';
    vm.results = [];
  };

  vm.removeStudent = function (i) {
    vm.compose.student_ids.splice(i, 1);
    vm.picked.splice(i, 1);
  };

  vm.send = function () {
    vm.busy = true;
    Api.post('sms/send', vm.compose)
      .then(function (res) {
        Toast.success(res.message);
        vm.compose.message = '';
        vm.picked = [];
        vm.compose.student_ids = [];
        if (vm.tab === 'log') { vm.loadLogs(); }
      })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.busy = false; });
  };

  vm.loadLogs = function () {
    vm.loading = true;
    Api.get('sms/logs')
      .then(function (res) { vm.logs = res.data; })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };

  vm.loadTemplates = function () {
    Api.get('sms/templates').then(function (res) { vm.templates = res.data; });
  };

  vm.saveTemplate = function (t) {
    Api.put('sms/templates/' + t.id, { body: t.body, active: t.active })
      .then(function (res) { Toast.success(res.message); })
      .catch(function (e) { Toast.error(e.message); });
  };

  vm.switchTab = function (tab) {
    vm.tab = tab;
    if (tab === 'log') { vm.loadLogs(); }
    if (tab === 'templates' && !vm.templates) { vm.loadTemplates(); }
  };
}]);
