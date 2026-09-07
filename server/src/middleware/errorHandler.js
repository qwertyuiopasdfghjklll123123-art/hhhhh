function notFoundHandler(req, res) {
  res.status(404).json({ error: 'المسار المطلوب غير موجود' });
}

// eslint-disable-next-line no-unused-vars
function errorHandler(err, req, res, next) {
  const statusCode = err.statusCode || 500;
  if (statusCode === 500) {
    console.error(err);
  }
  res.status(statusCode).json({
    error: err.message || 'حدث خطأ غير متوقع في الخادم',
  });
}

module.exports = { notFoundHandler, errorHandler };
