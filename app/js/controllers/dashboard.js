angular.module('schoolApp').controller('DashboardCtrl', ['ApiService', 'AuthService',
function (Api, Auth) {
  var vm = this;

  vm.loading = true;
  vm.data    = null;
  vm.user    = Auth.user;
  vm.today   = new Date();

  Api.get('dashboard')
    .then(function (res) { vm.data = res.data; })
    .catch(function (e) { vm.error = e.message; })
    .finally(function () { vm.loading = false; });

  /* Collection trend ke bars ki height ke liye */
  vm.barHeight = function (amount) {
    if (!vm.data || !vm.data.collection_trend.length) { return 0; }
    var max = Math.max.apply(null, vm.data.collection_trend.map(function (t) { return +t.amount; }));
    return max ? Math.round((+amount / max) * 100) : 0;
  };
}]);
