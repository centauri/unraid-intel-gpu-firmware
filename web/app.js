(() => {
  'use strict';
  const root = document.getElementById('igf');
  if (!root) return;
  const $ = id => document.getElementById(id);
  const api = root.dataset.api || '/plugins/intel.gpu.firmware/api.php';
  let state = {}, busy = false, confirmingPlan = null, pendingRequest = false, cardsKey = '', checkKey = '', historyKey = '', releaseKey = '', workloadKey = '';
  const element = (tag, text, cls) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (cls) node.className = cls;
    return node;
  };
  const message = text => { if ($('igf-status').textContent !== text) $('igf-status').textContent = text; };
  async function request(action, args = {}) {
    if (pendingRequest || busy) return false;
    pendingRequest = true;
    try {
      const r = await fetch(api, {method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body:new URLSearchParams({csrf_token:root.dataset.csrf,
          payload:JSON.stringify({action,csrf_token:root.dataset.csrf,...args})})});
      const data = await r.json();
      if (!r.ok) throw new Error(data.error || 'Request failed.');
      message('Operation queued. You can keep this page open to follow its status.');
      busy = true;
      await refresh();
      return true;
    } catch (e) {
      message(e.message);
      if ($('igf-confirm').open) $('igf-confirm-error').textContent = e.message;
      return false;
    }
    finally { pendingRequest = false; }
  }
  function facts(entries) {
    const dl = element('dl', undefined, 'igf-facts');
    entries.forEach(([key, value]) => {
      const wrap = element('div'); wrap.append(element('dt', key), element('dd', value)); dl.append(wrap);
    });
    return dl;
  }
  function renderCards() {
    const inventory = state.inventory || {};
    const key = JSON.stringify([inventory,state.staged?.checked_at,busy]);
    if (key === cardsKey) return;
    cardsKey = key;
    $('igf-scan-time').textContent = inventory.scanned_at ? 'Last scan: ' + new Date(inventory.scanned_at).toLocaleString() : 'Not scanned yet';
    const container = $('igf-cards');
    if (!inventory.scanned_at) return;
    container.replaceChildren();
    if (!inventory.cards?.length) {
      container.append(element('p', 'No Intel display controllers found. Check that the card is visible to the Unraid host.', 'igf-empty'));
    }
    (inventory.cards || []).forEach(card => {
      const article = element('article', undefined, 'igf-card');
      const head = element('div', undefined, 'igf-card-head');
      head.append(element('h3', card.name), element('span', card.capability === 'inspect' ? 'INSPECTABLE' : 'DETECTED', 'igf-badge'));
      article.append(head, element('p', card.board_vendor + ' · ' + card.bdf + ' · ' + card.vendor + ':' + card.device + ' / ' + card.subvendor + ':' + card.subdevice, 'igf-meta'));
      article.append(facts([['Current GSC bundle',card.firmware?.text || 'Not available'],['Host driver',card.driver],['Published evidence',card.validation?.label || 'No physical update evidence recorded']]));
      article.append(element('p', card.reason));
      if(card.validation?.detail)article.append(element('p',card.validation.detail));
      if (card.components) {
        const details = element('details');
        details.append(element('summary', 'Other firmware components · read only'));
        Object.entries(card.components).forEach(([key, value]) => details.append(element('h4', key),element('pre',value)));
        article.append(details);
      }
      if (card.capability === 'inspect') {
        const users=element('button','Review GPU users');users.type='button';users.disabled=busy;
        users.addEventListener('click',()=>request('workloads',{bdf:card.bdf}));article.append(users);
        const b = element('button', 'Check compatible firmware');
        b.type = 'button'; b.disabled = busy || !state.staged?.checked_at;
        b.addEventListener('click', () => request('check',{bdf:card.bdf}));
        article.append(b);
        if (!state.staged?.checked_at) article.append(element('p','Download the verified firmware package once to enable compatibility checks.'));
      }
      container.append(article);
    });
  }
  function renderCheck() {
    const check = state.check || {}, panel = $('igf-check-panel'), body = $('igf-check-body');
    const expired = Date.now() / 1000 > (check.plan?.expires_at || 0);
    const key = JSON.stringify([check,expired,busy,state.flash?.state,state.writes_enabled]);
    if (key === checkKey) return;
    checkKey = key;
    panel.hidden = !check.checked_at;
    if (panel.hidden) return;
    body.replaceChildren(element('p',check.reason));
    if (!check.eligible) return;
    const versions = element('div',undefined,'igf-version-pair');
    versions.append(element('span',check.plan.from.text),element('span','→'),element('strong',check.plan.to.text));
    body.append(versions,element('p','GPU ' + check.bdf + ' · GSC only · Intel package ' + check.plan.package));
    renderPowerAdvice(body,check.maintenance);
    const reasons = check.maintenance?.reasons || [];
    if (reasons.length) {
      body.append(element('strong','Before an update'));
      const list = element('ul',undefined,'igf-blockers');
      reasons.forEach(reason => list.append(element('li',reason))); body.append(list);
      body.append(element('p','Use Review GPU users to prepare targeted maintenance, then check compatibility again.'));
    }
    const dry = element('button','Run dry test');
    dry.type = 'button'; dry.disabled = busy;
    dry.addEventListener('click',()=>request('dry_run',{bdf:check.bdf}));
    body.append(dry,element('p','A dry test rechecks the card and image without writing firmware or stopping services.'));
    const b = element('button',state.writes_enabled !== true ? 'Firmware writing disabled in this build' : expired ? 'Plan expired · check again' : 'Review GSC update');
    b.type = 'button'; b.disabled = state.writes_enabled !== true || busy || expired || !check.maintenance?.ready || Boolean(state.flash?.state && !['complete','not_started'].includes(state.flash.state));
    b.addEventListener('click', () => {
      confirmingPlan = structuredClone(check.plan);
      $('igf-confirm-summary').replaceChildren(element('p', confirmingPlan.bdf + ' · ' + confirmingPlan.from.text + ' → ' + confirmingPlan.to.text));
      $('igf-expected').textContent = confirmingPlan.confirmation;
      $('igf-phrase').value = ''; $('igf-ack').checked = false; $('igf-write').disabled = true;
      $('igf-confirm-error').textContent = ''; $('igf-confirm').showModal(); $('igf-phrase').focus();
    });
    body.append(b);
  }
  const date = value => value ? new Date(value).toLocaleString() : 'Not checked';
  function renderWorkloads() {
    const p=state.workloads || {}, m=state.maintenance || {}, test=state.maintenance_test || {};
    const active=Boolean(m.state && m.state!=='complete');
    const expired=Date.now()/1000>(p.expires_at || 0);
    const key=JSON.stringify([p,m,test,busy,expired,state.flash?.state]);if(key===workloadKey)return;workloadKey=key;
    const panel=$('igf-workloads-panel'),body=$('igf-workloads-body');panel.hidden=!p.checked_at&&!m.state;if(panel.hidden)return;
    body.replaceChildren();const data=active?m:p;
    body.append(element('p','GPU '+(data.bdf || m.bdf)+' · '+(active?'Maintenance: '+m.state:'Review before stopping workloads')));
    body.append(table(['Type','Workload','Was running','GPU access'],(data.items || []).map(i=>[i.kind==='vm'?'VM':'Docker',i.name,i.running?'Yes':'No',i.reasons.join('; ')])));
    body.append(element('p','Only listed running workloads are stopped. Other containers and VMs keep running. Privileged containers have access to all host devices and are included.'));
    body.append(element('p','During maintenance, GPU workload starts are blocked, including requests from Komodo. Creating containers and changing orchestrators is temporarily blocked. Do not change GPU mappings, hot-plug PCI devices or use direct host GPU commands during maintenance.'));
    if(data.blockers?.length){const ul=element('ul');data.blockers.forEach(v=>ul.append(element('li',v)));body.append(ul);}
    if(m.message&&active)body.append(element('p',m.message));
    if(active) {
      body.append(element('p','Original restart policies and running states are saved. After a real update, reboot and verify the firmware before restoring workloads.'));
      const b=element('button','Restore GPU workloads');b.type='button';b.disabled=busy||Boolean(state.flash?.state&&!['complete','not_started','preflight'].includes(state.flash.state));
      b.addEventListener('click',()=>request('restore'));body.append(b);
    } else if(p.id) {
      const label=element('label',undefined,'igf-check-label'),ack=element('input');ack.type='checkbox';
      label.append(ack,document.createTextNode(' I approve temporarily stopping the listed running workloads.'));
      const prepare=element('button','Prepare GPU maintenance'),rehearse=element('button','Rehearse stop and restore · no flash');
      for(const b of [prepare,rehearse]){b.type='button';b.disabled=true;}
      const blocked=busy||expired||Boolean(p.blockers?.length)||Boolean(state.flash?.state&&!['complete','not_started'].includes(state.flash.state));
      ack.disabled=blocked;ack.addEventListener('change',()=>{prepare.disabled=rehearse.disabled=blocked||!ack.checked;});
      prepare.addEventListener('click',()=>request('prepare',{preview_id:p.id,acknowledged:ack.checked}));
      rehearse.addEventListener('click',()=>request('rehearse',{preview_id:p.id,acknowledged:ack.checked}));
      body.append(label,prepare,rehearse);
      if(expired)body.append(element('p','Preview expired. Click Review GPU users again.'));
    }
    if(test.checked_at)body.append(element('strong','Last maintenance rehearsal'),element('p',date(test.checked_at)+' · '+test.message));
  }
  function renderPowerAdvice(body,maintenance) {
    if(!maintenance?.power)return;
    const advice=element('div',undefined,'igf-power-advice');
    advice.append(element('strong','Power advice · optional'));
    const warnings=maintenance.warnings || [];
    advice.append(element('p',warnings.length ? warnings.join(' ') : maintenance.power.reason+' A UPS is recommended, not required.'));
    body.append(advice);
  }
  function table(headers, rows) {
    const wrap = element('div',undefined,'igf-table-wrap');
    const t = element('table',undefined,'igf-table'), head = element('thead'), tr = element('tr');
    headers.forEach(label => { const th=element('th',label);th.scope='col';tr.append(th); });
    head.append(tr);t.append(head);
    const body=element('tbody');
    rows.forEach(values=>{const row=element('tr');values.forEach(value=>row.append(element('td',value)));body.append(row);});
    t.append(body);wrap.append(t);return wrap;
  }
  function renderTracking() {
    $('igf-releases').disabled=busy;
    $('igf-history-warning').textContent=state.history_warning?.message || '';
    const history=state.history?.cards || {};
    const key=JSON.stringify(history);
    if(key!==historyKey) {
      historyKey=key;
      const body=$('igf-history-body');body.replaceChildren();
      if(!Object.keys(history).length)body.append(element('p','No firmware history yet. Run a scan to record readable versions. Existing update journals are included automatically.'));
      const outcomes={complete:'Verified after reboot',reboot_required:'Read-back passed; reboot verification pending',
        preflight:'Preparation interrupted; writer not invoked',not_started:'No firmware writer invoked',flashing:'Started; completion not confirmed',verifying:'Post-write verification pending',needs_attention:'Outcome uncertain; inspect update journal'};
      Object.values(history).forEach(device=>{
        const details=element('details',undefined,'igf-history-device');details.open=Object.keys(history).length===1;
        details.append(element('summary',device.name+' · '+device.identity.bdf));
        const seen=device.last_observation;
        if(seen) {
          details.append(element('p','Last recorded GSC: '+seen.versions.gsc+' · '+date(seen.at)));
          const components=element('details');components.append(element('summary','Last recorded component versions'));
          Object.entries(seen.versions.components || {}).forEach(([name,value])=>components.append(element('h4',name),element('pre',value)));
          details.append(components);
        }
        const events=[...(device.events || [])].sort((a,b)=>b.at.localeCompare(a.at));
        details.append(table(['Date','Event','GSC version','Result'],events.map(event=>{
          if(event.kind==='observed')return [date(event.at),'Version read',event.versions.gsc,'Observed on the GPU'];
          if(event.kind==='available')return [date(event.at),'Compatibility check',event.from+' → '+event.to,'Compatible at that time · package '+event.package];
          return [date(event.at),'Update',event.from+' → '+event.to,(outcomes[event.state] || 'Unknown outcome')+
            (event.verified_at?' · '+date(event.verified_at):'')+(event.package?' · package '+event.package:'')];
        })));
        if(device.older_events_pruned)details.append(element('p','Older events were removed; the most recent 100 are shown.'));
        body.append(details);
      });
    }
    const release=state.intel_release || {}, catalog=state.catalog || state.inventory?.package || {};
    const rk=JSON.stringify([release,catalog]);
    if(rk!==releaseKey) {
      releaseKey=rk;const body=$('igf-releases-body');body.replaceChildren();
      body.append(facts([['Reviewed package',catalog.version || 'Unavailable'],['Last seen at Intel',release.latest?.version || 'Not checked'],['Last successful check',date(release.checked_at)]]));
      const descriptions={catalog_current:'Intel currently lists the package already in this catalog. This does not establish that every firmware component is current.',
        new_package:'Intel lists a newer package. Its GSC versions and compatibility still need review before this plugin can offer it.',
        package_changed:'Intel changed the package without changing its version. It needs review; the trusted catalog is unchanged.',
        unexpected_older:'Intel returned an older package than this catalog. Availability is uncertain; no downgrade is offered.',
        unknown:'Latest release status is unknown. Any previous successful result below is historical.'};
      body.append(element('p',release.status ? (descriptions[release.status] || descriptions.unknown) : 'No online release check has been made.'));
      if(release.error)body.append(element('p','Last attempt '+date(release.attempted_at)+': '+release.error));
      if(release.catalog_version && release.catalog_version!==catalog.version)
        body.append(element('p','The last check used catalog '+release.catalog_version+'. Check again for the installed catalog.'));
      const link=element('a','Open Intel’s release page');link.href='https://www.intel.com/content/www/us/en/download/785597/intel-arc-graphics-windows.html';link.target='_blank';link.rel='noopener noreferrer';body.append(link);
      if(release.releases?.length) {
        const details=element('details');details.append(element('summary','Previously seen Intel packages'));
        details.append(table(['First seen','Package version'],[...release.releases].reverse().map(r=>[date(r.first_seen_at),r.package.version])));body.append(details);
      }
    }
  }
  function render() {
    const writable = state.writes_enabled === true;
    $('igf-release').textContent = writable ? 'DEVELOPER CANDIDATE' : 'PUBLIC ALPHA · WRITES DISABLED';
    $('igf-mode').textContent = writable
      ? 'Experimental GSC updater. One ASRock A380 update has been verified after reboot. Other hardware and interrupted-write recovery remain untested. Every update requires maintenance checks and confirmation of the exact GPU.'
      : 'Public alpha — physical firmware writes are disabled. Scan GPUs, check compatible GSC images and record versions. Optional maintenance rehearsals stop selected workloads only after your approval. No setting enables flashing.';
    $('igf-control').textContent = writable ? 'Explicit confirmation' : 'Public alpha testing';
    $('igf-control-detail').textContent = writable ? 'A verified image, idle GPU and your confirmation are required. A UPS is recommended, not required.' : 'Physical writes are blocked in this build, including direct API requests.';
    busy = ['queued','running'].includes(state.job?.state);
    $('igf-scan').disabled = busy; $('igf-stage').disabled = busy;
    if (state.job?.state === 'failed') message(state.job.message || 'Operation failed.');
    else if (busy) message((state.job.action === 'flash' ? 'Firmware update in progress. Do not reboot or remove power. ' : 'Working: ' + state.job.action + '. ') + 'The worker runs independently of this tab.');
    else if (state.job?.state === 'complete') message('Finished. ' + (state.staged?.checked_at ? 'Verified firmware package is available.' : ''));
    renderCards(); renderWorkloads(); renderCheck(); renderTracking();
    $('igf-dry-panel').hidden = !state.dry_run?.checked_at;
    if (state.dry_run?.checked_at) {
      const dry = state.dry_run;
      $('igf-dry-body').replaceChildren(element('p',dry.message),facts([
        ['Target GPU',dry.bdf],['Checks passed',String(Object.values(dry.checks || {}).filter(Boolean).length)],['Firmware written','No']]));
      const reasons = dry.maintenance?.reasons || [];
      if(reasons.length) {
        const ul=element('ul');reasons.forEach(reason=>ul.append(element('li',reason)));
        $('igf-dry-body').append(element('strong','Conditions that would block a physical update'),ul);
      }
      renderPowerAdvice($('igf-dry-body'),dry.maintenance);
    }
    $('igf-flash-panel').hidden = !state.flash?.state;
    $('igf-flash-body').replaceChildren(element('p',state.flash?.last_message || ''));
    if(state.flash?.state === 'flashing' && state.progress?.text)
      $('igf-flash-body').append(element('pre',state.progress.text));
    $('igf-verify').hidden = state.flash?.state !== 'reboot_required';
    $('igf-verify').disabled = busy;
  }
  async function refresh() {
    try {
      const r = await fetch(api + '?action=status',{credentials:'same-origin',cache:'no-store'});
      if (!r.ok) throw new Error('Could not read plugin status.');
      state = await r.json(); render();
    } catch (e) { message(e.message + ' Refreshing this page will not cancel a running update.'); }
  }
  $('igf-scan').addEventListener('click',()=>request('scan'));
  $('igf-stage').addEventListener('click',()=>request('stage'));
  $('igf-releases').addEventListener('click',()=>request('releases'));
  $('igf-report').href = api + '?action=report';
  $('igf-verify').addEventListener('click',()=>request('verify'));
  $('igf-cancel').addEventListener('click',()=>$('igf-confirm').close());
  const confirmChanged = () => { $('igf-write').disabled = !confirmingPlan || !$('igf-ack').checked || $('igf-phrase').value !== confirmingPlan.confirmation; };
  $('igf-phrase').addEventListener('input',confirmChanged); $('igf-ack').addEventListener('change',confirmChanged);
  $('igf-confirm-form').addEventListener('submit',async event=>{
    event.preventDefault(); if (!confirmingPlan || $('igf-write').disabled) return;
    $('igf-write').disabled = true;
    const ok = await request('flash',{plan_id:confirmingPlan.id,confirmation:$('igf-phrase').value,acknowledged:$('igf-ack').checked});
    if (ok) $('igf-confirm').close(); else confirmChanged();
  });
  refresh();
  setInterval(()=>{if(!document.hidden && !$('igf-confirm').open)refresh();},2500);
})();
