/* Patla $http wrapper — API base, error message nikalna, promise unwrap. */
angular.module('schoolApp').factory('ApiService', ['$http', '$q', 'API_BASE',
function ($http, $q, API_BASE) {

  function url(path) {
    return API_BASE + '/' + path.replace(/^\//, '');
  }

  function unwrap(res) {
    return res.data;
  }

  function fail(rej) {
    var msg = (rej.data && rej.data.message) ||
              (rej.status === -1 ? 'Cannot reach the server. Check that the API is running.' :
               'Something went wrong (HTTP ' + rej.status + ')');
    return $q.reject({ message: msg, errors: (rej.data && rej.data.errors) || {}, status: rej.status });
  }

  return {
    get: function (path, params) {
      return $http.get(url(path), { params: params || {} }).then(unwrap, fail);
    },
    post: function (path, body) {
      return $http.post(url(path), body || {}).then(unwrap, fail);
    },
    put: function (path, body) {
      return $http.put(url(path), body || {}).then(unwrap, fail);
    },
    del: function (path) {
      return $http.delete(url(path)).then(unwrap, fail);
    }
  };
}]);
