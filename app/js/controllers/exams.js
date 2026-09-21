/* ---------- Exam list ---------- */
angular.module('schoolApp').controller('ExamCtrl',
['ApiService', 'ToastService', 'AuthService', '$location', '$window',
function (Api, Toast, Auth, $location, $window) {
  var vm = this;

  vm.classes = [];
  vm.exams   = [];
  vm.loading = true;
  vm.isAdmin = Auth.is('admin');
  vm.canEdit = Auth.is('admin', 'teacher');
  vm.filter  = { class_id: '' };

  Api.get('classes').then(function (r) { vm.classes = r.data; });

  vm.load = function () {
    vm.loading = true;
    Api.get('exams', vm.filter)
      .then(function (res) { vm.exams = res.data; })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };
  vm.load();

  vm.openNew = function () {
    vm.draft  = { exam_date: new Date().toISOString().slice(0, 10) };
    vm.errors = {};
  };
  vm.closeNew = function () { vm.draft = null; };

  vm.create = function () {
    Api.post('exams', vm.draft)
      .then(function (res) { Toast.success(res.message); vm.draft = null; vm.load(); })
      .catch(function (e) { vm.errors = e.errors || {}; Toast.error(e.message); });
  };

  vm.enterMarks = function (exam) { $location.path('/exams/' + exam.id + '/marks'); };

  vm.publish = function (exam) {
    if (!$window.confirm('Publish results for ' + exam.name + '? Parents will get an SMS.')) { return; }
    Api.post('exams/' + exam.id + '/publish')
      .then(function (res) { Toast.success(res.message); vm.load(); })
      .catch(function (e) { Toast.error(e.message); });
  };
}]);

/* ---------- Marks entry grid ---------- */
angular.module('schoolApp').controller('MarksCtrl',
['ApiService', 'ToastService', '$routeParams', '$location',
function (Api, Toast, $routeParams, $location) {
  var vm = this;

  vm.loading = true;
  vm.busy    = false;

  Api.get('exams/' + $routeParams.id + '/marks')
    .then(function (res) {
      vm.exam     = res.data.exam;
      vm.subjects = res.data.subjects;
      vm.students = res.data.students;
    })
    .catch(function (e) { vm.error = e.message; })
    .finally(function () { vm.loading = false; });

  vm.total = function (student) {
    var sum = 0;
    vm.subjects.forEach(function (sub) {
      var v = parseFloat(student.marks[sub.id]);
      if (!isNaN(v)) { sum += v; }
    });
    return sum;
  };

  vm.maxTotal = function () {
    return vm.subjects.reduce(function (a, s) { return a + (+s.max_marks); }, 0);
  };

  vm.percentage = function (student) {
    var max = vm.maxTotal();
    return max ? Math.round(vm.total(student) * 1000 / max) / 10 : 0;
  };

  vm.overMax = function (student, sub) {
    var v = parseFloat(student.marks[sub.id]);
    return !isNaN(v) && (v < 0 || v > +sub.max_marks);
  };

  vm.hasErrors = function () {
    if (!vm.students) { return true; }
    return vm.students.some(function (st) {
      return vm.subjects.some(function (sub) { return vm.overMax(st, sub); });
    });
  };

  vm.save = function () {
    vm.busy = true;
    Api.post('exams/' + $routeParams.id + '/marks', {
      rows: vm.students.map(function (s) {
        return { student_id: s.id, marks: s.marks };
      })
    })
      .then(function (res) { Toast.success(res.message); })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.busy = false; });
  };

  vm.back = function () { $location.path('/exams'); };
}]);

/* ---------- Report card ---------- */
angular.module('schoolApp').controller('ReportCardCtrl',
['ApiService', 'ToastService', '$location', '$window',
function (Api, Toast, $location, $window) {
  var vm = this;

  vm.classes  = [];
  vm.students = [];
  vm.exams    = [];
  vm.pick     = { class_id: '', student_id: $location.search().student_id || '', exam_id: '' };
  vm.today    = new Date();

  Api.get('classes').then(function (r) { vm.classes = r.data; });

  vm.onClass = function () {
    vm.pick.student_id = '';
    vm.pick.exam_id    = '';
    vm.card            = null;
    if (!vm.pick.class_id) { return; }

    Api.get('students', { class_id: vm.pick.class_id, per_page: 100 })
      .then(function (r) { vm.students = r.data; });
    Api.get('exams', { class_id: vm.pick.class_id })
      .then(function (r) { vm.exams = r.data; });
  };

  vm.generate = function () {
    if (!vm.pick.student_id || !vm.pick.exam_id) {
      Toast.error('Choose a student and an exam');
      return;
    }
    vm.loading = true;
    Api.get('report-card/' + vm.pick.student_id, { exam_id: vm.pick.exam_id })
      .then(function (res) { vm.card = res.data; })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };

  vm.print = function () { $window.print(); };
}]);
