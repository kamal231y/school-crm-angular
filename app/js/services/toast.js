/* Chhote notifications, 4 second me apne aap hat jaate hain. */
angular.module('schoolApp').factory('ToastService', ['$timeout', function ($timeout) {
  var svc = { items: [] };

  function push(text, type) {
    var item = { text: text, type: type };
    svc.items.push(item);
    $timeout(function () {
      var i = svc.items.indexOf(item);
      if (i > -1) { svc.items.splice(i, 1); }
    }, 4000);
  }

  svc.success = function (t) { push(t, 'success'); };
  svc.error   = function (t) { push(t, 'error'); };
  svc.info    = function (t) { push(t, 'info'); };
  return svc;
}]);
