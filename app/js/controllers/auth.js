angular.module('schoolApp').controller('LoginCtrl', ['$location', 'AuthService', 'ToastService',
function ($location, Auth, Toast) {
  var vm = this;

  vm.creds   = { email: '', password: '' };
  vm.error   = null;
  vm.busy    = false;

  vm.submit = function () {
    if (vm.busy) { return; }
    vm.error = null;
    vm.busy  = true;

    Auth.login(vm.creds.email, vm.creds.password)
      .then(function (user) {
        Toast.success('Welcome back, ' + user.name.split(' ')[0]);
        $location.path('/dashboard');
      })
      .catch(function (err) { vm.error = err.message; })
      .finally(function () { vm.busy = false; });
  };

  vm.useDemo = function (email) {
    vm.creds.email = email;
    vm.creds.password = 'admin123';
  };
}]);
