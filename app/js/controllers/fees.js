/* ---------- Fee invoices, collection, defaulters ---------- */
angular.module('schoolApp').controller('FeeCtrl',
['ApiService', 'ToastService', 'AuthService', '$location', '$routeParams',
function (Api, Toast, Auth, $location, $routeParams) {
  var vm = this;

  vm.tab      = 'invoices';
  vm.classes  = [];
  vm.invoices = [];
  vm.summary  = {};
  vm.loading  = true;
  vm.canPost  = Auth.is('admin', 'accountant');
  vm.todayStr = new Date().toISOString().slice(0, 10);
  vm.filters  = {
    status: '',
    class_id: '',
    student_id: $location.search().student_id || ''
  };

  Api.get('classes').then(function (r) { vm.classes = r.data; });

  vm.load = function () {
    vm.loading = true;
    Api.get('fees/invoices', vm.filters)
      .then(function (res) {
        vm.invoices = res.data.invoices;
        vm.summary  = res.data.summary;
      })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };
  vm.load();

  vm.loadDefaulters = function () {
    vm.loading = true;
    Api.get('fees/defaulters')
      .then(function (res) { vm.defaulters = res.data; })
      .catch(function (e) { Toast.error(e.message); })
      .finally(function () { vm.loading = false; });
  };

  vm.switchTab = function (tab) {
    vm.tab = tab;
    if (tab === 'defaulters' && !vm.defaulters) { vm.loadDefaulters(); }
  };

  /* --- collect payment modal --- */
  vm.openPay = function (inv) {
    vm.pay = {
      invoice_id: inv.id,
      amount:     +inv.balance,
      mode:       'cash',
      paid_on:    new Date().toISOString().slice(0, 10),
      send_sms:   true,
      _invoice:   inv
    };
    vm.payErrors = {};
  };
  vm.closePay = function () { vm.pay = null; };

  vm.submitPay = function () {
    vm.payBusy = true;
    Api.post('fees/payments', vm.pay)
      .then(function (res) {
        Toast.success(res.message);
        var id = res.data.payment_id;
        vm.pay = null;
        vm.load();
        $location.path('/fees/receipt/' + id);
      })
      .catch(function (e) { vm.payErrors = e.errors || {}; Toast.error(e.message); })
      .finally(function () { vm.payBusy = false; });
  };

  /* --- new invoice modal --- */
  vm.openInvoice = function () {
    vm.newInvoice = {
      due_date: new Date(new Date().setDate(10)).toISOString().slice(0, 10),
      period: new Date().toLocaleString('en-IN', { month: 'short', year: 'numeric' })
    };
    vm.invErrors = {};
    vm.studentQuery = '';
    vm.studentResults = [];
  };
  vm.closeInvoice = function () { vm.newInvoice = null; };

  vm.searchStudent = function () {
    if (!vm.studentQuery || vm.studentQuery.length < 2) { vm.studentResults = []; return; }
    Api.get('students', { search: vm.studentQuery, per_page: 8 })
      .then(function (res) { vm.studentResults = res.data; });
  };
  vm.pickStudent = function (s) {
    vm.newInvoice.student_id = s.id;
    vm.pickedStudent = s;
    vm.studentResults = [];
    vm.studentQuery = s.first_name + ' ' + (s.last_name || '');
  };

  vm.submitInvoice = function () {
    vm.invBusy = true;
    Api.post('fees/invoices', vm.newInvoice)
      .then(function (res) { Toast.success(res.message); vm.newInvoice = null; vm.load(); })
      .catch(function (e) { vm.invErrors = e.errors || {}; Toast.error(e.message); })
      .finally(function () { vm.invBusy = false; });
  };
}]);

/* ---------- Printable receipt ---------- */
angular.module('schoolApp').controller('ReceiptCtrl', ['ApiService', '$routeParams', '$window',
function (Api, $routeParams, $window) {
  var vm = this;
  vm.loading = true;

  Api.get('fees/receipt/' + $routeParams.id)
    .then(function (res) { vm.r = res.data; })
    .catch(function (e) { vm.error = e.message; })
    .finally(function () { vm.loading = false; });

  vm.print = function () { $window.print(); };
}]);
