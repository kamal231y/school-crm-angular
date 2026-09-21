/* ============================================================
   Sunrise School CRM — AngularJS 1.x front end
   ============================================================ */
angular.module('schoolApp', ['ngRoute'])

  /* API base URL. Agar app/ aur api/ ek hi host par hain to yeh theek hai. */
  .constant('API_BASE', '../api/index.php')

  .config(['$routeProvider', '$locationProvider', '$httpProvider',
  function ($routeProvider, $locationProvider, $httpProvider) {

    $locationProvider.hashPrefix('!');

    var guarded = {
      resolve: {
        session: ['AuthService', function (Auth) { return Auth.restore(); }]
      }
    };
    function route(templateUrl, controller) {
      return angular.extend({ templateUrl: templateUrl, controller: controller, controllerAs: 'vm' }, guarded);
    }

    $routeProvider
      .when('/login',              { templateUrl: 'views/login.html', controller: 'LoginCtrl', controllerAs: 'vm' })
      .when('/dashboard',          route('views/dashboard.html',         'DashboardCtrl'))
      .when('/students',           route('views/students.html',          'StudentListCtrl'))
      .when('/students/:id',       route('views/student-profile.html',   'StudentProfileCtrl'))
      .when('/admission',          route('views/admission.html',         'AdmissionCtrl'))
      .when('/attendance',         route('views/attendance.html',        'AttendanceCtrl'))
      .when('/fees',               route('views/fees.html',              'FeeCtrl'))
      .when('/fees/receipt/:id',   route('views/receipt.html',           'ReceiptCtrl'))
      .when('/exams',              route('views/exams.html',             'ExamCtrl'))
      .when('/exams/:id/marks',    route('views/marks.html',             'MarksCtrl'))
      .when('/report-card',        route('views/report-card.html',       'ReportCardCtrl'))
      .when('/sms',                route('views/sms.html',               'SmsCtrl'))
      .when('/reports/students',   route('views/report-students.html',   'StudentReportCtrl'))
      .when('/reports/attendance', route('views/report-attendance.html', 'AttendanceReportCtrl'))
      .when('/reports/fees',       route('views/report-fees.html',       'FeeReportCtrl'))
      .otherwise({ redirectTo: '/dashboard' });

    /* Har request me token lagao, 401 par login par bhejo. */
    $httpProvider.interceptors.push(['$q', '$location', '$injector', function ($q, $location, $injector) {
      return {
        request: function (config) {
          var token = localStorage.getItem('crm_token');
          if (token && config.url.indexOf('http') !== 0) {
            config.headers.Authorization = 'Bearer ' + token;
          }
          return config;
        },
        responseError: function (rej) {
          if (rej.status === 401) {
            localStorage.removeItem('crm_token');
            localStorage.removeItem('crm_user');
            $injector.get('AuthService').clear();
            $location.path('/login');
          }
          return $q.reject(rej);
        }
      };
    }]);
  }])

  .run(['$rootScope', '$location', 'AuthService', function ($rootScope, $location, Auth) {
    $rootScope.$on('$routeChangeStart', function (evt, next) {
      var path = $location.path();
      if (path !== '/login' && !Auth.isLoggedIn()) {
        $location.path('/login');
      }
      if (path === '/login' && Auth.isLoggedIn()) {
        $location.path('/dashboard');
      }
    });
    $rootScope.$on('$routeChangeError', function () { $location.path('/login'); });
  }])

  /* Shell controller: sidebar, user chip, logout */
  .controller('ShellCtrl', ['$location', 'AuthService', 'ToastService',
  function ($location, Auth, Toast) {
    var vm   = this;
    vm.auth  = Auth;
    vm.toast = Toast;
    vm.today = new Date();

    vm.isActive = function (prefix) {
      return $location.path().indexOf(prefix) === 0;
    };
    vm.logout = function () {
      Auth.logout().finally(function () { $location.path('/login'); });
    };
  }])

  /* ₹ formatting: {{ 12500 | rupee }} -> ₹12,500.00 */
  .filter('rupee', function () {
    return function (value, noSymbol) {
      var n = parseFloat(value);
      if (isNaN(n)) { n = 0; }
      var s = n.toFixed(2);
      var parts = s.split('.');
      var last3 = parts[0].slice(-3);
      var rest  = parts[0].slice(0, -3);
      var out   = rest ? rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + last3 : last3;
      return (noSymbol ? '' : '₹') + out + '.' + parts[1];
    };
  })

  /* Percentage ko safe tarah se dikhane ke liye */
  .filter('pct', function () {
    return function (value) {
      var n = parseFloat(value);
      return (isNaN(n) ? 0 : n) + '%';
    };
  })

  /* Title case: 'admin' -> 'Admin', 'in_progress' -> 'In Progress' */
  .filter('titlecase', function () {
    return function (str) {
      if (!str) { return ''; }
      return String(str)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, function (txt) { return txt.toUpperCase(); });
    };
  });
