import { useState, useEffect } from 'react';
import { Download, Save, RefreshCw, Code, Palette, Type, Table as TableIcon, ChevronDown, ChevronUp } from 'lucide-react';
import { useAuth } from '../context/AuthContext';

const API_BASE = '/api/api.php';

interface ParsedConfig {
  title: string;
  subtitle: string;
  primaryColor: string;
  secondaryColor: string;
  accentColor: string;
  pricingRows: Array<{
    duration: string;
    description: string;
    speed: string;
    price: string;
  }>;
  selectedSkin?: string;
}

export function CaptivePage() {
  const { user } = useAuth();
  const [originalHtml, setOriginalHtml] = useState('');
  const [modifiedHtml, setModifiedHtml] = useState('');
  const [parsedConfig, setParsedConfig] = useState<ParsedConfig | null>(null);
  const [filename, setFilename] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saveMessage, setSaveMessage] = useState('');
  const [showHtmlEditor, setShowHtmlEditor] = useState(false);
  const [activeTab, setActiveTab] = useState<'content' | 'colors' | 'pricing' | 'skins'>('content');
  const [selectedSkin, setSelectedSkin] = useState<string>('default');

  useEffect(() => {
    loadTemplate();
  }, []);

  const loadTemplate = async () => {
    setLoading(true);
    try {
      const response = await fetch(`${API_BASE}?action=get_captive_template`, {
        credentials: 'include',
      });
      
      if (!response.ok) {
        throw new Error('Failed to load template');
      }
      
      const data = await response.json();
      
      if (data.ok) {
        setOriginalHtml(data.content);
        setModifiedHtml(data.content);
        setFilename(data.filename);
        parseTemplate(data.content);
      } else {
        throw new Error(data.error || 'Unknown error');
      }
    } catch (error) {
      console.error('Error loading template:', error);
      alert('Failed to load template: ' + error);
    } finally {
      setLoading(false);
    }
  };

  const parseTemplate = (html: string) => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    
    // Extract title
    const h1 = doc.querySelector('h1');
    const title = h1?.textContent?.trim() || '';
    
    // Extract subtitle
    const subtitle = doc.querySelector('.subtitle')?.textContent?.trim() || '';
    
    // Extract colors from style tag
    const styleTag = doc.querySelector('style');
    const styleContent = styleTag?.textContent || '';
    
    // Try to extract gradient colors
    let primaryColor = '#2a5298';
    let secondaryColor = '#1e3c72';
    let accentColor = '#ff6b35';
    
    const gradientMatch = styleContent.match(/linear-gradient\([^)]*#([0-9a-fA-F]{6})[^)]*#([0-9a-fA-F]{6})/);
    if (gradientMatch) {
      primaryColor = '#' + gradientMatch[1];
      secondaryColor = '#' + gradientMatch[2];
    }
    
    const accentMatch = styleContent.match(/background:\s*linear-gradient[^#]*#([0-9a-fA-F]{6})/);
    if (accentMatch) {
      accentColor = '#' + accentMatch[1];
    }
    
    // Extract pricing rows
    const pricingRows: ParsedConfig['pricingRows'] = [];
    const rows = doc.querySelectorAll('.pricing-table tr');
    rows.forEach(row => {
      const cells = row.querySelectorAll('td');
      if (cells.length >= 3) {
        const durationEl = cells[0].querySelector('.package-duration');
        const descEl = cells[0].querySelector('.package-desc');
        const duration = durationEl?.textContent?.trim() || cells[0].textContent?.trim() || '';
        const description = descEl?.textContent?.trim() || '';
        const speed = cells[1].textContent?.trim() || '';
        const priceText = cells[2].textContent?.trim() || '';
        const price = priceText.replace(/[^0-9,]/g, '');
        
        if (duration) {
          pricingRows.push({ duration, description, speed, price });
        }
      }
    });
    
    setParsedConfig({
      title,
      subtitle,
      primaryColor,
      secondaryColor,
      accentColor,
      pricingRows: pricingRows.length > 0 ? pricingRows : [
        { duration: '1 Hour', description: 'Quick Browse', speed: '5 Mbps', price: '500' }
      ],
    });
  };

  const applyChanges = () => {
    if (!parsedConfig) return originalHtml;
    
    let html = originalHtml;
    
    // Replace title
    html = html.replace(/<h1[^>]*>.*?<\/h1>/s, `<h1>${parsedConfig.title}</h1>`);
    
    // Replace subtitle
    html = html.replace(/<p class="subtitle"[^>]*>.*?<\/p>/s, `<p class="subtitle">${parsedConfig.subtitle}</p>`);
    
    // Replace colors in style tag
    html = html.replace(
      /linear-gradient\(135deg,\s*#[0-9a-fA-F]{6}\s*0%,\s*#[0-9a-fA-F]{6}\s*100%\)/g,
      `linear-gradient(135deg, ${parsedConfig.primaryColor} 0%, ${parsedConfig.secondaryColor} 100%)`
    );
    
    html = html.replace(
      /background:\s*linear-gradient\(135deg,\s*#[0-9a-fA-F]{6}\s*0%,\s*#[0-9a-fA-F]{6}[a-z0-9]*\s*100%\)/g,
      `background: linear-gradient(135deg, ${parsedConfig.accentColor} 0%, ${parsedConfig.accentColor}dd 100%)`
    );
    
    // Replace pricing table rows
    const pricingTableRegex = /<table class="pricing-table">[\s\S]*?<\/table>/;
    const pricingTableMatch = html.match(pricingTableRegex);
    
    if (pricingTableMatch) {
      const newRows = parsedConfig.pricingRows.map(row => `
                <tr>
                    <td>
                        <span class="package-duration">${row.duration}</span>
                        <span class="package-desc">${row.description}</span>
                    </td>
                    <td>${row.speed}</td>
                    <td><button class="buy-btn">UGX ${row.price}</button></td>
                </tr>`).join('\n');
      
      const newTable = `<table class="pricing-table">
${newRows}
            </table>`;
      
      html = html.replace(pricingTableRegex, newTable);
    }
    
    return html;
  };

  const updateConfig = (updates: Partial<ParsedConfig>) => {
    if (!parsedConfig) return;
    const newConfig = { ...parsedConfig, ...updates };
    setParsedConfig(newConfig);
    setModifiedHtml(generateSkinHtml(newConfig, selectedSkin));
  };

  // Update preview when skin changes
  useEffect(() => {
    if (parsedConfig) {
      setModifiedHtml(generateSkinHtml(parsedConfig, selectedSkin));
    }
  }, [selectedSkin]);

  const generateSkinHtml = (config: ParsedConfig, skinId: string) => {
    // Generate HTML based on selected skin while preserving all data
    if (skinId === 'skin2') {
      return generateGlassmorphismSkin(config);
    } else if (skinId === 'skin3') {
      return generateNeumorphismSkin(config);
    } else {
      // Default to skin1 (modern gradient) - apply changes to original
      return applyChangesToOriginal(config);
    }
  };

  const applyChangesToOriginal = (config: ParsedConfig) => {
    let html = originalHtml;
    
    html = html.replace(/<h1[^>]*>.*?<\/h1>/s, `<h1>${config.title}</h1>`);
    html = html.replace(/<p class="subtitle"[^>]*>.*?<\/p>/s, `<p class="subtitle">${config.subtitle}</p>`);
    
    html = html.replace(
      /linear-gradient\(135deg,\s*#[0-9a-fA-F]{6}\s*0%,\s*#[0-9a-fA-F]{6}\s*100%\)/g,
      `linear-gradient(135deg, ${config.primaryColor} 0%, ${config.secondaryColor} 100%)`
    );
    
    html = html.replace(
      /background:\s*linear-gradient\(135deg,\s*#[0-9a-fA-F]{6}\s*0%,\s*#[0-9a-fA-F]{6}[a-z0-9]*\s*100%\)/g,
      `background: linear-gradient(135deg, ${config.accentColor} 0%, ${config.accentColor}dd 100%)`
    );
    
    const pricingTableRegex = /<table class="pricing-table">[\s\S]*?<\/table>/;
    const newRows = config.pricingRows.map(row => `
                <tr>
                    <td>
                        <span class="package-duration">${row.duration}</span>
                        <span class="package-desc">${row.description}</span>
                    </td>
                    <td>${row.speed}</td>
                    <td><button class="buy-btn">UGX ${row.price}</button></td>
                </tr>`).join('\n');
    
    const newTable = `<table class="pricing-table">
${newRows}
            </table>`;
    
    html = html.replace(pricingTableRegex, newTable);
    
    return html;
  };

  const applyChangesToHtml = (config: ParsedConfig) => {
    return generateSkinHtml(config, selectedSkin);
  };

  const generateGlassmorphismSkin = (config: ParsedConfig) => {
    const pricingRows = config.pricingRows.map(row => `
                <tr>
                    <td>
                        <span class="package-duration">${row.duration}</span>
                        <span class="package-desc">${row.description}</span>
                    </td>
                    <td>${row.speed}</td>
                    <td><button class="buy-btn">UGX ${row.price}</button></td>
                </tr>`).join('\n');

    return `<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.85">
    <title>${config.title}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, ${config.primaryColor} 0%, ${config.secondaryColor} 100%);
            min-height: 100vh;
            color: #ffffff;
            padding: 20px;
            position: relative;
        }
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.05)" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,138.7C960,139,1056,117,1152,101.3C1248,85,1344,75,1392,69.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            pointer-events: none;
        }
        .container { max-width: 700px; margin: 0 auto; padding-top: 20px; position: relative; z-index: 1; }
        .glass-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
            padding: 25px;
            margin-bottom: 20px;
        }
        .header { text-align: center; margin-bottom: 30px; }
        h1 { font-size: 32px; font-weight: 700; color: #ffffff; margin-bottom: 10px; text-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .subtitle { font-size: 16px; color: rgba(255, 255, 255, 0.9); font-weight: 400; }
        .input-group { display: flex; gap: 10px; margin-bottom: 15px; }
        #voucherCode {
            flex: 1;
            padding: 14px 18px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            color: #ffffff;
            font-size: 16px;
            outline: none;
            transition: all 0.3s;
        }
        #voucherCode::placeholder { color: rgba(255, 255, 255, 0.6); }
        #voucherCode:focus {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.2);
        }
        .login-btn {
            width: 100%;
            padding: 14px;
            background: ${config.accentColor};
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        .login-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3); }
        .divider { text-align: center; margin: 25px 0; color: rgba(255, 255, 255, 0.7); font-size: 14px; }
        .pricing-table { width: 100%; border-collapse: separate; border-spacing: 0 12px; }
        .pricing-table tr {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            transition: all 0.3s;
        }
        .pricing-table tr:hover { background: rgba(255, 255, 255, 0.18); transform: translateY(-2px); }
        .pricing-table td { padding: 16px; color: #ffffff; border: none; }
        .pricing-table td:first-child { border-radius: 12px 0 0 12px; }
        .pricing-table td:last-child { border-radius: 0 12px 12px 0; text-align: right; }
        .package-duration { font-size: 18px; font-weight: 600; display: block; margin-bottom: 4px; }
        .package-desc { font-size: 13px; color: rgba(255, 255, 255, 0.7); display: block; }
        .buy-btn {
            padding: 10px 24px;
            background: ${config.accentColor};
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .buy-btn:hover { transform: scale(1.05); box-shadow: 0 6px 16px rgba(0, 0, 0, 0.25); }
        .footer-text { text-align: center; margin-top: 25px; color: rgba(255, 255, 255, 0.6); font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>${config.title}</h1>
            <p class="subtitle">${config.subtitle}</p>
        </div>
        <div class="glass-card">
            <div class="input-group">
                <input type="text" id="voucherCode" placeholder="Enter voucher code">
            </div>
            <button class="login-btn">Connect</button>
        </div>
        <div class="divider">Or buy a voucher</div>
        <div class="glass-card">
            <table class="pricing-table">${pricingRows}
            </table>
        </div>
        <div class="footer-text">Powered by WiFi Hotspot</div>
    </div>
</body>
</html>`;
  };

  const generateNeumorphismSkin = (config: ParsedConfig) => {
    const pricingRows = config.pricingRows.map(row => `
                <tr>
                    <td>
                        <span class="package-duration">${row.duration}</span>
                        <span class="package-desc">${row.description}</span>
                    </td>
                    <td>${row.speed}</td>
                    <td><button class="buy-btn">UGX ${row.price}</button></td>
                </tr>`).join('\n');

    return `<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.85">
    <title>${config.title}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #2d3748;
            min-height: 100vh;
            color: #e2e8f0;
            padding: 20px;
        }
        .container { max-width: 700px; margin: 0 auto; padding-top: 20px; }
        .neu-card {
            background: #2d3748;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 8px 8px 16px #1a202c, -8px -8px 16px #404d64;
        }
        .neu-card-inset {
            background: #2d3748;
            border-radius: 16px;
            padding: 20px;
            box-shadow: inset 4px 4px 8px #1a202c, inset -4px -4px 8px #404d64;
        }
        .header { text-align: center; margin-bottom: 30px; }
        h1 { font-size: 32px; font-weight: 700; color: ${config.accentColor}; margin-bottom: 10px; }
        .subtitle { font-size: 16px; color: #a0aec0; font-weight: 400; }
        .input-group { margin-bottom: 20px; }
        #voucherCode {
            width: 100%;
            padding: 16px 20px;
            background: #2d3748;
            border: none;
            border-radius: 12px;
            color: #e2e8f0;
            font-size: 16px;
            outline: none;
            box-shadow: inset 3px 3px 6px #1a202c, inset -3px -3px 6px #404d64;
            transition: all 0.3s;
        }
        #voucherCode::placeholder { color: #718096; }
        #voucherCode:focus { box-shadow: inset 5px 5px 10px #1a202c, inset -5px -5px 10px #404d64; }
        .login-btn {
            width: 100%;
            padding: 16px;
            background: ${config.accentColor};
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 6px 6px 12px #1a202c, -6px -6px 12px #404d64;
        }
        .login-btn:hover { box-shadow: 8px 8px 16px #1a202c, -8px -8px 16px #404d64; }
        .login-btn:active { box-shadow: inset 4px 4px 8px #1a202c, inset -4px -4px 8px #404d64; }
        .divider { text-align: center; margin: 30px 0; color: #718096; font-size: 14px; font-weight: 500; }
        .pricing-table { width: 100%; border-collapse: separate; border-spacing: 0 15px; }
        .pricing-table tr {
            background: #2d3748;
            border-radius: 12px;
            box-shadow: 5px 5px 10px #1a202c, -5px -5px 10px #404d64;
            transition: all 0.3s;
        }
        .pricing-table tr:hover { box-shadow: 7px 7px 14px #1a202c, -7px -7px 14px #404d64; }
        .pricing-table td { padding: 18px; color: #e2e8f0; border: none; }
        .pricing-table td:first-child { border-radius: 12px 0 0 12px; }
        .pricing-table td:last-child { border-radius: 0 12px 12px 0; text-align: right; }
        .package-duration { font-size: 18px; font-weight: 600; display: block; margin-bottom: 4px; color: ${config.accentColor}; }
        .package-desc { font-size: 13px; color: #a0aec0; display: block; }
        .buy-btn {
            padding: 12px 26px;
            background: ${config.accentColor};
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 4px 4px 8px #1a202c, -4px -4px 8px #404d64;
        }
        .buy-btn:hover { box-shadow: 6px 6px 12px #1a202c, -6px -6px 12px #404d64; }
        .buy-btn:active { box-shadow: inset 3px 3px 6px #1a202c, inset -3px -3px 6px #404d64; }
        .footer-text { text-align: center; margin-top: 30px; color: #718096; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>${config.title}</h1>
            <p class="subtitle">${config.subtitle}</p>
        </div>
        <div class="neu-card">
            <div class="input-group">
                <input type="text" id="voucherCode" placeholder="Enter voucher code">
            </div>
            <button class="login-btn">Connect</button>
        </div>
        <div class="divider">Or buy a voucher</div>
        <div class="neu-card">
            <table class="pricing-table">${pricingRows}
            </table>
        </div>
        <div class="footer-text">Powered by WiFi Hotspot</div>
    </div>
</body>
</html>`;
  };

  const updatePricingRow = (index: number, field: string, value: string) => {
    if (!parsedConfig) return;
    const newRows = [...parsedConfig.pricingRows];
    newRows[index] = { ...newRows[index], [field]: value };
    updateConfig({ pricingRows: newRows });
  };

  const addPricingRow = () => {
    if (!parsedConfig) return;
    updateConfig({
      pricingRows: [...parsedConfig.pricingRows, { duration: 'New', description: 'Package', speed: '10 Mbps', price: '1,000' }]
    });
  };

  const removePricingRow = (index: number) => {
    if (!parsedConfig || parsedConfig.pricingRows.length <= 1) return;
    const newRows = parsedConfig.pricingRows.filter((_, i) => i !== index);
    updateConfig({ pricingRows: newRows });
  };

  const saveTemplate = async () => {
    setSaving(true);
    setSaveMessage('');
    try {
      const htmlToSave = showHtmlEditor ? modifiedHtml : applyChanges();
      
      const response = await fetch(`${API_BASE}?action=save_captive_template`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ content: htmlToSave }),
      });
      
      if (!response.ok) {
        throw new Error('Failed to save');
      }
      
      const data = await response.json();
      
      if (data.ok) {
        setOriginalHtml(htmlToSave);
        setSaveMessage('✓ Saved successfully!');
        setTimeout(() => setSaveMessage(''), 3000);
      } else {
        throw new Error(data.error || 'Unknown error');
      }
    } catch (error) {
      console.error('Error saving template:', error);
      alert('Failed to save template: ' + error);
    } finally {
      setSaving(false);
    }
  };

  const downloadTemplate = () => {
    const htmlToDownload = showHtmlEditor ? modifiedHtml : applyChanges();
    const blob = new Blob([htmlToDownload], { type: 'text/html' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename || 'captive-page.html';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <RefreshCw className="w-6 h-6 text-primary animate-spin" />
      </div>
    );
  }

  if (!parsedConfig) {
    return (
      <div className="flex items-center justify-center h-64 text-destructive">
        Failed to parse template
      </div>
    );
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center justify-between flex-wrap gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-1">Captive Page Editor</h1>
          <p className="text-sm text-muted-foreground">
            Editing: <span className="font-mono text-primary">{filename}</span>
          </p>
        </div>
        <div className="flex gap-2 flex-wrap">
          <button
            onClick={() => setShowHtmlEditor(!showHtmlEditor)}
            className="px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors flex items-center gap-2"
          >
            <Code className="w-4 h-4" />
            {showHtmlEditor ? 'Visual Editor' : 'HTML Editor'}
          </button>
          <button
            onClick={saveTemplate}
            disabled={saving}
            className="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors flex items-center gap-2 disabled:opacity-50"
          >
            <Save className="w-4 h-4" />
            {saving ? 'Saving...' : 'Save'}
          </button>
          <button
            onClick={downloadTemplate}
            className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-2"
          >
            <Download className="w-4 h-4" />
            Download
          </button>
        </div>
      </div>

      {saveMessage && (
        <div className="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
          {saveMessage}
        </div>
      )}

      {showHtmlEditor ? (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div className="bg-card border border-border rounded-lg overflow-hidden">
            <div className="bg-muted px-4 py-2 border-b border-border">
              <span className="text-sm font-medium text-foreground">HTML Editor</span>
            </div>
            <textarea
              value={modifiedHtml}
              onChange={(e) => setModifiedHtml(e.target.value)}
              className="w-full h-[700px] p-4 bg-slate-900 text-green-400 font-mono text-sm resize-none focus:outline-none"
              spellCheck={false}
            />
          </div>
          <div className="bg-card border border-border rounded-lg overflow-hidden">
            <div className="bg-muted px-4 py-2 border-b border-border">
              <span className="text-sm font-medium text-foreground">Preview</span>
            </div>
            <div className="bg-white">
              <iframe
                srcDoc={modifiedHtml}
                className="w-full h-[700px] border-0"
                title="Preview"
                sandbox="allow-same-origin"
              />
            </div>
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-1 space-y-4">
            <div className="bg-card border border-border rounded-lg">
              <div className="flex border-b border-border overflow-x-auto">
                {[
                  { id: 'content', icon: Type, label: 'Content' },
                  { id: 'colors', icon: Palette, label: 'Colors' },
                  { id: 'pricing', icon: TableIcon, label: 'Pricing' },
                  { id: 'skins', icon: Palette, label: 'Skins' },
                ].map((tab) => (
                  <button
                    key={tab.id}
                    onClick={() => setActiveTab(tab.id as any)}
                    className={`flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium transition-colors ${
                      activeTab === tab.id
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-muted'
                    }`}
                  >
                    <tab.icon className="w-4 h-4" />
                    <span className="hidden sm:inline">{tab.label}</span>
                  </button>
                ))}
              </div>

              <div className="p-4 space-y-4 max-h-[600px] overflow-y-auto">
                {activeTab === 'content' && (
                  <>
                    <div>
                      <label className="block text-sm font-medium text-foreground mb-2">Page Title</label>
                      <input
                        type="text"
                        value={parsedConfig.title}
                        onChange={(e) => updateConfig({ title: e.target.value })}
                        className="w-full px-3 py-2 bg-input-background border border-border rounded text-foreground"
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-foreground mb-2">Subtitle</label>
                      <input
                        type="text"
                        value={parsedConfig.subtitle}
                        onChange={(e) => updateConfig({ subtitle: e.target.value })}
                        className="w-full px-3 py-2 bg-input-background border border-border rounded text-foreground"
                      />
                    </div>
                  </>
                )}

                {activeTab === 'colors' && (
                  <>
                    <div>
                      <label className="block text-sm font-medium text-foreground mb-2">Primary Color</label>
                      <div className="flex gap-2">
                        <input
                          type="color"
                          value={parsedConfig.primaryColor}
                          onChange={(e) => updateConfig({ primaryColor: e.target.value })}
                          className="w-16 h-10 rounded border border-border cursor-pointer"
                        />
                        <input
                          type="text"
                          value={parsedConfig.primaryColor}
                          onChange={(e) => updateConfig({ primaryColor: e.target.value })}
                          className="flex-1 px-3 py-2 bg-input-background border border-border rounded text-foreground text-sm"
                        />
                      </div>
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-foreground mb-2">Secondary Color</label>
                      <div className="flex gap-2">
                        <input
                          type="color"
                          value={parsedConfig.secondaryColor}
                          onChange={(e) => updateConfig({ secondaryColor: e.target.value })}
                          className="w-16 h-10 rounded border border-border cursor-pointer"
                        />
                        <input
                          type="text"
                          value={parsedConfig.secondaryColor}
                          onChange={(e) => updateConfig({ secondaryColor: e.target.value })}
                          className="flex-1 px-3 py-2 bg-input-background border border-border rounded text-foreground text-sm"
                        />
                      </div>
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-foreground mb-2">Accent Color</label>
                      <div className="flex gap-2">
                        <input
                          type="color"
                          value={parsedConfig.accentColor}
                          onChange={(e) => updateConfig({ accentColor: e.target.value })}
                          className="w-16 h-10 rounded border border-border cursor-pointer"
                        />
                        <input
                          type="text"
                          value={parsedConfig.accentColor}
                          onChange={(e) => updateConfig({ accentColor: e.target.value })}
                          className="flex-1 px-3 py-2 bg-input-background border border-border rounded text-foreground text-sm"
                        />
                      </div>
                    </div>
                  </>
                )}

                {activeTab === 'skins' && (
                  <div className="space-y-4">
                    <div>
                      <h3 className="font-semibold text-foreground mb-3">Select Design Skin</h3>
                      <p className="text-sm text-muted-foreground mb-4">Choose a visual style for your captive page. All your content and data will be preserved.</p>
                    </div>

                    <div className="space-y-3">
                      {[
                        { id: 'skin1', name: 'Modern Gradient', desc: 'Bold gradient design with vibrant colors', color: 'from-purple-600 to-blue-600' },
                        { id: 'skin2', name: 'Glassmorphism', desc: 'Frosted glass effect with blur and transparency', color: 'from-cyan-400 to-blue-500' },
                        { id: 'skin3', name: 'Dark Neumorphism', desc: 'Modern dark theme with soft shadows', color: 'from-gray-800 to-gray-900' },
                      ].map((skin) => (
                        <button
                          key={skin.id}
                          onClick={() => setSelectedSkin(skin.id)}
                          className={`w-full p-4 rounded-lg border-2 transition-all text-left ${
                            selectedSkin === skin.id
                              ? 'border-primary bg-primary/5'
                              : 'border-border hover:border-primary/50'
                          }`}
                        >
                          <div className="flex items-start gap-3">
                            <div className={`w-12 h-12 rounded-lg bg-gradient-to-br ${skin.color} flex-shrink-0`}></div>
                            <div className="flex-1">
                              <div className="font-semibold text-foreground mb-1">{skin.name}</div>
                              <div className="text-xs text-muted-foreground">{skin.desc}</div>
                            </div>
                            {selectedSkin === skin.id && (
                              <div className="text-primary font-bold">✓</div>
                            )}
                          </div>
                        </button>
                      ))}
                    </div>

                    <div className="mt-4 p-3 bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 rounded-lg">
                      <p className="text-xs text-blue-800 dark:text-blue-200">
                        💡 <strong>Tip:</strong> Changing skins only affects the visual design. Your title, colors, pricing data, and all functionality remain unchanged.
                      </p>
                    </div>
                  </div>
                )}

                {activeTab === 'pricing' && (
                  <div className="space-y-4">
                    <div className="flex items-center justify-between">
                      <h3 className="font-semibold text-foreground">Pricing Table</h3>
                      <button
                        onClick={addPricingRow}
                        className="px-3 py-1 bg-primary text-primary-foreground rounded text-sm"
                      >
                        + Add Row
                      </button>
                    </div>

                    {parsedConfig.pricingRows.map((row, index) => (
                      <div key={index} className="p-3 bg-muted rounded-lg space-y-2">
                        <div className="flex items-center justify-between mb-2">
                          <span className="text-sm font-medium text-foreground">Row {index + 1}</span>
                          {parsedConfig.pricingRows.length > 1 && (
                            <button
                              onClick={() => removePricingRow(index)}
                              className="text-xs text-destructive hover:underline"
                            >
                              Remove
                            </button>
                          )}
                        </div>
                        <input
                          type="text"
                          value={row.duration}
                          onChange={(e) => updatePricingRow(index, 'duration', e.target.value)}
                          placeholder="Duration"
                          className="w-full px-2 py-1 bg-background border border-border rounded text-sm"
                        />
                        <input
                          type="text"
                          value={row.description}
                          onChange={(e) => updatePricingRow(index, 'description', e.target.value)}
                          placeholder="Description"
                          className="w-full px-2 py-1 bg-background border border-border rounded text-sm"
                        />
                        <input
                          type="text"
                          value={row.speed}
                          onChange={(e) => updatePricingRow(index, 'speed', e.target.value)}
                          placeholder="Speed"
                          className="w-full px-2 py-1 bg-background border border-border rounded text-sm"
                        />
                        <input
                          type="text"
                          value={row.price}
                          onChange={(e) => updatePricingRow(index, 'price', e.target.value)}
                          placeholder="Price"
                          className="w-full px-2 py-1 bg-background border border-border rounded text-sm"
                        />
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>

          <div className="lg:col-span-2">
            <div className="bg-card border border-border rounded-lg overflow-hidden sticky top-6">
              <div className="bg-muted px-4 py-2 border-b border-border">
                <span className="text-sm font-medium text-foreground">Live Preview</span>
              </div>
              <div className="bg-white">
                <iframe
                  srcDoc={modifiedHtml}
                  className="w-full h-[700px] border-0"
                  title="Preview"
                  sandbox="allow-same-origin"
                />
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
