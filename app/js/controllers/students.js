/* ---------- All students ---------- */
angular.module('schoolApp').controller('StudentListCtrl', ['ApiService', 'ToastService', '$location',
function (Api, Toast, $location) {
  var vm = this;

  vm.filters = { search: '', class_id: '', status: 'active', page: 1, per_page: 15 };
  vm.rows    = [];
  vm.meta    = {};
  vm.classes = [];
  vm.loading = true;

  Api.get('classes').then(function (r) { vm.classes = r.data; });

  vm.load = function (page) {
    vm.filters.page = page || 1;
    vm.loading = true;
    Api.get('students', vm.filters)
      .then(function (res) { vm.rows = res.data; vm.meta = res.meta; })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };
  vm.load();

  vm.open = function (id) { $location.path('/students/' + id); };

  vm.reset = function () {
    vm.filters.search = '';
    vm.filters.class_id = '';
    vm.filters.status = 'active';
    vm.load(1);
  };
}]);

/* ---------- New admission ---------- */
angular.module('schoolApp').controller('AdmissionCtrl', ['ApiService', 'ToastService', '$location',
function (Api, Toast, $location) {
  var vm = this;

  vm.classes = [];
  vm.errors  = {};
  vm.busy    = false;
  vm.saved   = null;

  vm.form = {
    gender: 'male',
    admission_date: new Date().toISOString().slice(0, 10)
  };

  Api.get('classes').then(function (r) { vm.classes = r.data; });

  vm.save = function () {
    vm.busy   = true;
    vm.errors = {};

    Api.post('students', vm.form)
      .then(function (res) {
        vm.saved = res.data;
        Toast.success(res.message);
        vm.form = { gender: 'male', admission_date: new Date().toISOString().slice(0, 10) };
      })
      .catch(function (e) {
        vm.errors = e.errors || {};
        Toast.error(e.message);
      })
      .finally(function () { vm.busy = false; });
  };

  vm.openStudent = function () { $location.path('/students/' + vm.saved.id); };
}]);

/* ---------- Single student profile ---------- */
angular.module('schoolApp').controller('StudentProfileCtrl',
['ApiService', 'ToastService', '$routeParams', '$location',
function (Api, Toast, $routeParams, $location) {
  var vm = this;

  vm.id      = $routeParams.id;
  vm.loading = true;
  vm.editing = false;
  vm.classes = [];
  vm.errors  = {};

  Api.get('classes').then(function (r) { vm.classes = r.data; });

  function load() {
    vm.loading = true;
    Api.get('students/' + vm.id)
      .then(function (res) {
        vm.student    = res.data.student;
        vm.fees       = res.data.fees;
        vm.attendance = res.data.attendance;
        vm.exams      = res.data.exams;
        vm.sms        = res.data.recent_sms;
        vm.draft      = angular.copy(res.data.student);
      })
      .catch(function (e) { vm.error = e.message; })
      .finally(function () { vm.loading = false; });
  }
  load();

  vm.save = function () {
    vm.errors = {};
    Api.put('students/' + vm.id, vm.draft)
      .then(function (res) {
        Toast.success(res.message);
        vm.editing = false;
        load();
      })
      .catch(function (e) { vm.errors = e.errors || {}; Toast.error(e.message); });
  };

  vm.cancel = function () {
    vm.draft   = angular.copy(vm.student);
    vm.editing = false;
    vm.errors  = {};
  };

  vm.goReportCard = function () { $location.path('/report-card').search({ student_id: vm.id }); };
  vm.goFees       = function () { $location.path('/fees').search({ student_id: vm.id }); };
}]);
