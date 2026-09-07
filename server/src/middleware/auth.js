const { verifyToken } = require('../utils/jwt');
const ApiError = require('../utils/ApiError');

// يتحقق من وجود توكن JWT صالح ويضع بيانات المستخدم في req.user
function requireAuth(req, res, next) {
  const header = req.headers.authorization || '';
  const token = header.startsWith('Bearer ') ? header.slice(7) : null;
  if (!token) return next(new ApiError(401, 'يجب تسجيل الدخول أولاً'));
  try {
    const payload = verifyToken(token);
    req.user = { id: payload.sub, role: payload.role, name: payload.name };
    next();
  } catch (err) {
    next(new ApiError(401, 'جلسة الدخول غير صالحة أو منتهية'));
  }
}

// نفس requireAuth لكن لا يفشل إن لم يوجد توكن (لعرض محتوى عام مع تخصيص بسيط)
function optionalAuth(req, res, next) {
  const header = req.headers.authorization || '';
  const token = header.startsWith('Bearer ') ? header.slice(7) : null;
  if (!token) return next();
  try {
    const payload = verifyToken(token);
    req.user = { id: payload.sub, role: payload.role, name: payload.name };
  } catch (err) {
    // تجاهل التوكن غير الصالح في الوضع الاختياري
  }
  next();
}

function requireAdmin(req, res, next) {
  if (!req.user || req.user.role !== 'admin') {
    return next(new ApiError(403, 'هذا الإجراء متاح للمشرفين فقط'));
  }
  next();
}

module.exports = { requireAuth, optionalAuth, requireAdmin };
