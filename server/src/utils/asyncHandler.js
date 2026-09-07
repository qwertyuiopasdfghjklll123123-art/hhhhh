// يلف دوال الراوت الـ async حتى تُمرَّر أي أخطاء تلقائياً إلى errorHandler
// بدل تكرار try/catch في كل route
module.exports = function asyncHandler(fn) {
  return function wrapped(req, res, next) {
    Promise.resolve(fn(req, res, next)).catch(next);
  };
};
