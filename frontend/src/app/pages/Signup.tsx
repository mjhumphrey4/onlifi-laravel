import { useState } from 'react';
import { useNavigate, Link } from 'react-router';
import { User, Mail, Lock, Phone, UserPlus, CheckCircle, AlertCircle, Eye, EyeOff, Loader2 } from 'lucide-react';

interface SignupFormData {
  username: string;
  email: string;
  password: string;
  confirmPassword: string;
  full_name: string;
  phone: string;
}

interface ValidationErrors {
  username?: string;
  email?: string;
  password?: string;
  confirmPassword?: string;
  full_name?: string;
}

export function Signup() {
  const navigate = useNavigate();
  const [formData, setFormData] = useState<SignupFormData>({
    username: '',
    email: '',
    password: '',
    confirmPassword: '',
    full_name: '',
    phone: '',
  });
  const [errors, setErrors] = useState<ValidationErrors>({});
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [success, setSuccess] = useState(false);
  const [apiError, setApiError] = useState('');

  const validateForm = (): boolean => {
    const newErrors: ValidationErrors = {};

    if (!formData.username.trim()) {
      newErrors.username = 'Username is required';
    } else if (formData.username.length < 3) {
      newErrors.username = 'Username must be at least 3 characters';
    } else if (!/^[a-zA-Z0-9_]+$/.test(formData.username)) {
      newErrors.username = 'Username can only contain letters, numbers, and underscores';
    }

    if (!formData.email.trim()) {
      newErrors.email = 'Email is required';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      newErrors.email = 'Invalid email address';
    }

    if (!formData.full_name.trim()) {
      newErrors.full_name = 'Full name is required';
    }

    if (!formData.password) {
      newErrors.password = 'Password is required';
    } else if (formData.password.length < 8) {
      newErrors.password = 'Password must be at least 8 characters';
    } else if (!/[A-Z]/.test(formData.password)) {
      newErrors.password = 'Password must contain at least one uppercase letter';
    } else if (!/[a-z]/.test(formData.password)) {
      newErrors.password = 'Password must contain at least one lowercase letter';
    } else if (!/[0-9]/.test(formData.password)) {
      newErrors.password = 'Password must contain at least one number';
    }

    if (formData.password !== formData.confirmPassword) {
      newErrors.confirmPassword = 'Passwords do not match';
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
    if (errors[name as keyof ValidationErrors]) {
      setErrors(prev => ({ ...prev, [name]: undefined }));
    }
    setApiError('');
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!validateForm()) {
      return;
    }

    setLoading(true);
    setApiError('');

    try {
      const response = await fetch('/api/auth_api.php?action=signup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          username: formData.username,
          email: formData.email,
          password: formData.password,
          full_name: formData.full_name,
          phone: formData.phone,
        }),
      });

      const data = await response.json();

      if (data.success) {
        setSuccess(true);
        setTimeout(() => {
          navigate('/login');
        }, 2000);
      } else {
        setApiError(data.error || 'Failed to create account');
      }
    } catch (error) {
      console.error('Signup error:', error);
      setApiError('Network error. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  const getPasswordStrength = () => {
    const password = formData.password;
    if (!password) return { strength: 0, label: '', color: '' };

    let strength = 0;
    if (password.length >= 8) strength++;
    if (password.length >= 12) strength++;
    if (/[a-z]/.test(password)) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;

    if (strength <= 2) return { strength: 33, label: 'Weak', color: 'bg-red-500' };
    if (strength <= 4) return { strength: 66, label: 'Medium', color: 'bg-yellow-500' };
    return { strength: 100, label: 'Strong', color: 'bg-emerald-500' };
  };

  const passwordStrength = getPasswordStrength();

  if (success) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-primary/10 via-background to-primary/5 flex items-center justify-center p-4">
        <div className="bg-card border border-border rounded-2xl p-8 max-w-md w-full text-center shadow-xl">
          <div className="w-16 h-16 bg-emerald-500/10 rounded-full flex items-center justify-center mx-auto mb-4">
            <CheckCircle className="w-10 h-10 text-emerald-500" />
          </div>
          <h2 className="text-2xl font-bold text-card-foreground mb-2">Account Created!</h2>
          <p className="text-muted-foreground mb-4">
            Your account has been created successfully. Redirecting to login...
          </p>
          <div className="flex items-center justify-center gap-2 text-sm text-muted-foreground">
            <Loader2 className="w-4 h-4 animate-spin" />
            <span>Redirecting...</span>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-primary/10 via-background to-primary/5 flex items-center justify-center p-4">
      <div className="bg-card border border-border rounded-2xl shadow-2xl max-w-md w-full overflow-hidden">
        {/* Header */}
        <div className="bg-gradient-to-r from-primary to-primary/80 p-8 text-center">
          <div className="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-4">
            <UserPlus className="w-8 h-8 text-white" />
          </div>
          <h1 className="text-3xl font-bold text-white mb-2">Create Account</h1>
          <p className="text-white/80">Join Onlifi Network Manager today</p>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="p-8 space-y-5">
          {apiError && (
            <div className="bg-destructive/10 border border-destructive/20 rounded-lg p-4 flex items-start gap-3">
              <AlertCircle className="w-5 h-5 text-destructive flex-shrink-0 mt-0.5" />
              <p className="text-sm text-destructive">{apiError}</p>
            </div>
          )}

          {/* Full Name */}
          <div>
            <label className="block text-sm font-medium text-card-foreground mb-2">
              Full Name *
            </label>
            <div className="relative">
              <User className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-muted-foreground" />
              <input
                type="text"
                name="full_name"
                value={formData.full_name}
                onChange={handleChange}
                className={`w-full pl-11 pr-4 py-3 bg-background border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all ${
                  errors.full_name ? 'border-destructive' : 'border-border'
                }`}
                placeholder="John Doe"
              />
            </div>
            {errors.full_name && (
              <p className="text-xs text-destructive mt-1">{errors.full_name}</p>
            )}
          </div>

          {/* Username */}
          <div>
            <label className="block text-sm font-medium text-card-foreground mb-2">
              Username *
            </label>
            <div className="relative">
              <User className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-muted-foreground" />
              <input
                type="text"
                name="username"
                value={formData.username}
                onChange={handleChange}
                className={`w-full pl-11 pr-4 py-3 bg-background border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all ${
                  errors.username ? 'border-destructive' : 'border-border'
                }`}
                placeholder="johndoe"
              />
            </div>
            {errors.username && (
              <p className="text-xs text-destructive mt-1">{errors.username}</p>
            )}
          </div>

          {/* Email */}
          <div>
            <label className="block text-sm font-medium text-card-foreground mb-2">
              Email Address *
            </label>
            <div className="relative">
              <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-muted-foreground" />
              <input
                type="email"
                name="email"
                value={formData.email}
                onChange={handleChange}
                className={`w-full pl-11 pr-4 py-3 bg-background border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all ${
                  errors.email ? 'border-destructive' : 'border-border'
                }`}
                placeholder="john@example.com"
              />
            </div>
            {errors.email && (
              <p className="text-xs text-destructive mt-1">{errors.email}</p>
            )}
          </div>

          {/* Phone */}
          <div>
            <label className="block text-sm font-medium text-card-foreground mb-2">
              Phone Number
            </label>
            <div className="relative">
              <Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-muted-foreground" />
              <input
                type="tel"
                name="phone"
                value={formData.phone}
                onChange={handleChange}
                className="w-full pl-11 pr-4 py-3 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all"
                placeholder="+256 700 000 000"
              />
            </div>
          </div>

          {/* Password */}
          <div>
            <label className="block text-sm font-medium text-card-foreground mb-2">
              Password *
            </label>
            <div className="relative">
              <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-muted-foreground" />
              <input
                type={showPassword ? 'text' : 'password'}
                name="password"
                value={formData.password}
                onChange={handleChange}
                className={`w-full pl-11 pr-12 py-3 bg-background border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all ${
                  errors.password ? 'border-destructive' : 'border-border'
                }`}
                placeholder="••••••••"
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-card-foreground transition-colors"
              >
                {showPassword ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
              </button>
            </div>
            {formData.password && (
              <div className="mt-2">
                <div className="flex items-center justify-between mb-1">
                  <span className="text-xs text-muted-foreground">Password strength:</span>
                  <span className={`text-xs font-semibold ${
                    passwordStrength.strength === 100 ? 'text-emerald-500' :
                    passwordStrength.strength === 66 ? 'text-yellow-500' : 'text-red-500'
                  }`}>
                    {passwordStrength.label}
                  </span>
                </div>
                <div className="w-full bg-muted rounded-full h-1.5">
                  <div
                    className={`h-1.5 rounded-full transition-all ${passwordStrength.color}`}
                    style={{ width: `${passwordStrength.strength}%` }}
                  />
                </div>
              </div>
            )}
            {errors.password && (
              <p className="text-xs text-destructive mt-1">{errors.password}</p>
            )}
          </div>

          {/* Confirm Password */}
          <div>
            <label className="block text-sm font-medium text-card-foreground mb-2">
              Confirm Password *
            </label>
            <div className="relative">
              <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-muted-foreground" />
              <input
                type={showConfirmPassword ? 'text' : 'password'}
                name="confirmPassword"
                value={formData.confirmPassword}
                onChange={handleChange}
                className={`w-full pl-11 pr-12 py-3 bg-background border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition-all ${
                  errors.confirmPassword ? 'border-destructive' : 'border-border'
                }`}
                placeholder="••••••••"
              />
              <button
                type="button"
                onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-card-foreground transition-colors"
              >
                {showConfirmPassword ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
              </button>
            </div>
            {errors.confirmPassword && (
              <p className="text-xs text-destructive mt-1">{errors.confirmPassword}</p>
            )}
          </div>

          {/* Submit Button */}
          <button
            type="submit"
            disabled={loading}
            className="w-full bg-primary hover:bg-primary/90 text-primary-foreground font-semibold py-3 px-4 rounded-lg transition-all flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg hover:shadow-xl"
          >
            {loading ? (
              <>
                <Loader2 className="w-5 h-5 animate-spin" />
                Creating Account...
              </>
            ) : (
              <>
                <UserPlus className="w-5 h-5" />
                Create Account
              </>
            )}
          </button>

          {/* Login Link */}
          <div className="text-center pt-4 border-t border-border">
            <p className="text-sm text-muted-foreground">
              Already have an account?{' '}
              <Link to="/login" className="text-primary hover:text-primary/80 font-semibold transition-colors">
                Sign in
              </Link>
            </p>
          </div>
        </form>
      </div>
    </div>
  );
}
