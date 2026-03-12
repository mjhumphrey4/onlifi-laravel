import { useState, useRef, useCallback } from 'react';
import { Upload, FileText, CheckCircle, AlertTriangle, X } from 'lucide-react';
import { apiImportVouchers } from '../utils/api';
import { useAuth } from '../context/AuthContext';

interface ImportResult {
  imported: number;
  skipped: number;
  type_detected: string;
  errors: string[];
}

export function ImportVouchers() {
  const { userSites } = useAuth();
  const sites = userSites();

  const [selectedSite, setSelectedSite] = useState(sites[0] ?? '');
  const [file, setFile] = useState<File | null>(null);
  const [dragging, setDragging] = useState(false);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<ImportResult | null>(null);
  const [error, setError] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);

  const handleFile = (f: File) => {
    if (f.type !== 'application/pdf' && !f.name.endsWith('.pdf')) {
      setError('Only PDF files are supported.');
      return;
    }
    setFile(f);
    setResult(null);
    setError('');
  };

  const onDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setDragging(false);
    const f = e.dataTransfer.files[0];
    if (f) handleFile(f);
  }, []);

  const onDragOver = (e: React.DragEvent) => { e.preventDefault(); setDragging(true); };
  const onDragLeave = () => setDragging(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) { setError('Please select a PDF file.'); return; }
    if (!selectedSite) { setError('Please select a site.'); return; }
    setLoading(true);
    setError('');
    setResult(null);
    try {
      const res = await apiImportVouchers(selectedSite, file);
      setResult(res);
      setFile(null);
      if (inputRef.current) inputRef.current.value = '';
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Import failed');
    } finally {
      setLoading(false);
    }
  };

  const clearFile = () => {
    setFile(null);
    setResult(null);
    setError('');
    if (inputRef.current) inputRef.current.value = '';
  };

  const typeLabel: Record<string, string> = {
    '2hours': '2 Hours', '3hours': '3 Hours', '12hours': '12 Hours', '24hours': '24 Hours',
    '7days': '7 Days', '30days': '30 Days',
  };

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8">
        <h1 className="text-2xl sm:text-3xl text-foreground mb-2">Import Vouchers</h1>
        <p className="text-sm sm:text-base text-muted-foreground">
          Upload a PDF file to import voucher codes into your site's database.
        </p>
      </div>

      <div className="max-w-2xl">
        <form onSubmit={handleSubmit} className="space-y-6">

          {/* Site selector */}
          {sites.length > 1 && (
            <div className="bg-card border border-border rounded-lg p-5">
              <label className="block text-sm font-medium text-card-foreground mb-3">
                Select Site
              </label>
              <div className="flex flex-wrap gap-2">
                {sites.map((s) => (
                  <button
                    key={s}
                    type="button"
                    onClick={() => { setSelectedSite(s); setResult(null); setError(''); }}
                    className={`px-4 py-2 rounded-lg text-sm transition-colors ${
                      selectedSite === s
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-muted-foreground hover:bg-muted/80'
                    }`}
                  >
                    {s}
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* Drop zone */}
          <div className="bg-card border border-border rounded-lg p-5">
            <label className="block text-sm font-medium text-card-foreground mb-3">
              Voucher PDF File
            </label>

            {file ? (
              <div className="flex items-center gap-3 p-4 bg-primary/10 border border-primary/20 rounded-lg">
                <FileText className="w-8 h-8 text-primary flex-shrink-0" />
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-card-foreground truncate">{file.name}</p>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    {(file.size / 1024).toFixed(1)} KB
                  </p>
                </div>
                <button
                  type="button"
                  onClick={clearFile}
                  className="p-1.5 text-muted-foreground hover:text-destructive transition-colors rounded"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            ) : (
              <div
                onDrop={onDrop}
                onDragOver={onDragOver}
                onDragLeave={onDragLeave}
                onClick={() => inputRef.current?.click()}
                className={`border-2 border-dashed rounded-lg p-10 text-center cursor-pointer transition-colors ${
                  dragging
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:border-primary/50 hover:bg-muted/30'
                }`}
              >
                <Upload className="w-10 h-10 text-muted-foreground mx-auto mb-3" />
                <p className="text-sm font-medium text-card-foreground">
                  Drag & drop a PDF here, or <span className="text-primary">click to browse</span>
                </p>
                <p className="text-xs text-muted-foreground mt-1">PDF files only</p>
              </div>
            )}

            <input
              ref={inputRef}
              type="file"
              accept=".pdf,application/pdf"
              className="hidden"
              onChange={(e) => { const f = e.target.files?.[0]; if (f) handleFile(f); }}
            />
          </div>

          {/* Error */}
          {error && (
            <div className="flex items-start gap-3 p-4 rounded-lg bg-destructive/10 border border-destructive/20 text-destructive text-sm">
              <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
              {error}
            </div>
          )}

          {/* Submit */}
          <button
            type="submit"
            disabled={loading || !file || !selectedSite}
            className="w-full flex items-center justify-center gap-2 px-6 py-3 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors text-sm font-medium disabled:opacity-60"
          >
            {loading ? (
              <>
                <div className="w-4 h-4 border-2 border-primary-foreground border-t-transparent rounded-full animate-spin" />
                Importing…
              </>
            ) : (
              <>
                <Upload className="w-4 h-4" />
                Import Vouchers
              </>
            )}
          </button>
        </form>

        {/* Result */}
        {result && (
          <div className="mt-6 bg-card border border-border rounded-lg p-5 space-y-4">
            <div className="flex items-center gap-2">
              <CheckCircle className="w-5 h-5 text-primary" />
              <h2 className="text-base font-semibold text-card-foreground">Import Complete</h2>
            </div>

            <div className="grid grid-cols-3 gap-3">
              <div className="bg-primary/10 border border-primary/20 rounded-lg p-4 text-center">
                <p className="text-2xl font-bold text-primary">{result.imported}</p>
                <p className="text-xs text-muted-foreground mt-1">Imported</p>
              </div>
              <div className="bg-muted border border-border rounded-lg p-4 text-center">
                <p className="text-2xl font-bold text-card-foreground">{result.skipped}</p>
                <p className="text-xs text-muted-foreground mt-1">Skipped</p>
              </div>
              <div className="bg-muted border border-border rounded-lg p-4 text-center">
                <p className="text-sm font-semibold text-card-foreground">
                  {typeLabel[result.type_detected] ?? result.type_detected}
                </p>
                <p className="text-xs text-muted-foreground mt-1">Type Detected</p>
              </div>
            </div>

            {result.errors.length > 0 && (
              <div className="bg-yellow-500/10 border border-yellow-500/20 rounded-lg p-4">
                <p className="text-xs font-semibold text-yellow-500 mb-2">
                  Skipped / Errors {result.errors.length >= 20 ? '(first 20 shown)' : ''}
                </p>
                <ul className="space-y-1">
                  {result.errors.map((e, i) => (
                    <li key={i} className="text-xs text-muted-foreground font-mono">{e}</li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}

        {/* Info box */}
        <div className="mt-6 p-4 bg-muted/50 border border-border rounded-lg">
          <p className="text-xs font-semibold text-muted-foreground mb-2">How it works</p>
          <ul className="text-xs text-muted-foreground space-y-1 list-disc list-inside">
            <li>Upload the PDF voucher sheet generated by your system</li>
            <li>The voucher type (2h, 12h, 24h, 7d, 30d) is auto-detected from the PDF content</li>
            <li>Duplicate codes are automatically skipped</li>
            <li>Codes are imported directly into <strong className="text-card-foreground">{selectedSite || 'your site'}</strong>'s database</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
