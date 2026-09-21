/* Login state — token localStorage me, user memory + localStorage me. */
angular.module('schoolApp').factory('AuthService', ['$q', 'ApiService',
function ($q, Api) {

  var svc = {
    user:    JSON.parse(localStorage.getItem('crm_user') || 'null'),
    school:  localStorage.getItem('crm_school') || '',
    session: localStorage.getItem('crm_session') || ''
  };

  svc.isLoggedIn = function () {
    return !!localStorage.getItem('crm_token') && !!svc.user;
  };

  svc.login = function (email, password) {
    return Api.post('auth/login', { email: email, password: password }).then(function (res) {
      localStorage.setItem('crm_token', res.data.token);
      localStorage.setItem('crm_user', JSON.stringify(res.data.user));
      localStorage.setItem('crm_school', res.data.school || '');
      localStorage.setItem('crm_session', res.data.session || '');
      svc.user    = res.data.user;
      svc.school  = res.data.school;
      svc.session = res.data.session;
      return res.data.user;
    });
  };

  svc.logout = function () {
    return Api.post('auth/logout').finally(svc.clear);
  };

  svc.clear = function () {
    localStorage.removeItem('crm_token');
    localStorage.removeItem('crm_user');
    svc.user = null;
  };

  /* Route guard: page reload par token verify karta hai. */
  svc.restore = function () {
    if (!localStorage.getItem('crm_token')) {
      return $q.reject('no-token');
    }
    if (svc.user) {
      return $q.resolve(svc.user);
    }
    return Api.get('auth/me').then(function (res) {
      svc.user = res.data.user;
      localStorage.setItem('crm_user', JSON.stringify(svc.user));
      return svc.user;
    });
  };

  svc.is = function () {
    var roles = Array.prototype.slice.call(arguments);
    return !!svc.user && roles.indexOf(svc.user.role) > -1;
  };

  return svc;
}]);
