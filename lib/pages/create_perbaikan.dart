// lib/pages/create_perbaikandata.dart
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../services/api_service.dart'; // <— penting

class CreatePerbaikanDataScreen extends StatefulWidget {
  const CreatePerbaikanDataScreen({super.key});

  @override
  State<CreatePerbaikanDataScreen> createState() =>
      _CreatePerbaikanDataScreenState();
}

class _CreatePerbaikanDataScreenState extends State<CreatePerbaikanDataScreen> {
  final _formKey = GlobalKey<FormState>();

  // ===== DROPDOWN (backend) =====
  List<OptionItem> _departments = [];
  List<OptionItem> _employees = [];
  List<OptionItem> _categories = [];
  List<OptionItem> _customers = [];

  int? _deptId, _empId, _catId, _custId;

  final TextEditingController _pilihanCtrl = TextEditingController();
  final TextEditingController _dataBaruCtrl = TextEditingController();

  // ===== Address (backend wilayah) =====
  final TextEditingController _zipCtrl = TextEditingController();
  final TextEditingController _detailAddrCtrl = TextEditingController();

  List<OptionItem> _provinces = [];
  List<OptionItem> _cities = [];
  List<OptionItem> _districts = [];
  List<OptionItem> _villages = [];

  int? _provCode, _cityCode, _distCode, _villCode;

  // ===== Gambar =====
  final ImagePicker _picker = ImagePicker();
  final List<XFile> _photos = [];
  bool _submitting = false;
  bool _loadingOptions = false;

  @override
  void initState() {
    super.initState();
    _loadOptions();
    _loadProvinces();
  }

  Future<void> _loadOptions() async {
    setState(() => _loadingOptions = true);
    try {
      final depts = await ApiService.fetchDepartments(); // sudah ada
      setState(() => _departments = depts);
      if (depts.isNotEmpty) {
        _deptId = depts.first.id;
        await _onDeptChanged(_deptId);
      }

      final cats = await ApiService.fetchCustomerCategoriesAll(); // sudah ada
      setState(() {
        _categories = cats;
        if (cats.isNotEmpty) _catId = cats.first.id;
      });

      // customers akan di-load setelah dept+emp+cat terpilih
      await _loadCustomers();
    } finally {
      if (mounted) setState(() => _loadingOptions = false);
    }
  }

  Future<void> _onDeptChanged(int? id) async {
    setState(() {
      _deptId = id;
      _empId = null;
      _employees = [];
    });
    if (id == null) return;
    final emps = await ApiService.fetchEmployees(departmentId: id);
    setState(() {
      _employees = emps;
      if (emps.isNotEmpty) _empId = emps.first.id;
    });
    await _loadCustomers();
  }

  Future<void> _onEmpChanged(int? id) async {
    setState(() => _empId = id);
    await _loadCustomers();
  }

  Future<void> _onCatChanged(int? id) async {
    setState(() => _catId = id);
    await _loadCustomers();
  }

  Future<void> _loadCustomers() async {
    if (_deptId == null || _empId == null) return;
    final list = await ApiService.fetchPerbaikanCustomers(
      departmentId: _deptId,
      employeeId: _empId,
      categoryId: _catId,
    );
    setState(() {
      _customers = list;
      _custId = list.isNotEmpty ? list.first.id : null;
    });
  }

  Future<void> _loadProvinces() async {
    final provs = await ApiService.fetchProvinces();
    setState(() => _provinces = provs);
  }

  Future<void> _onProvinceChanged(int? code) async {
    setState(() {
      _provCode = code;
      _cityCode = _distCode = _villCode = null;
      _cities = _districts = _villages = [];
      _zipCtrl.clear();
    });
    if (code != null) {
      _cities = await ApiService.fetchCities('$code');
      setState(() {});
    }
  }

  Future<void> _onCityChanged(int? code) async {
    setState(() {
      _cityCode = code;
      _distCode = _villCode = null;
      _districts = _villages = [];
      _zipCtrl.clear();
    });
    if (code != null) {
      _districts = await ApiService.fetchDistricts('$code');
      setState(() {});
    }
  }

  Future<void> _onDistrictChanged(int? code) async {
    setState(() {
      _distCode = code;
      _villCode = null;
      _villages = [];
      _zipCtrl.clear();
    });
    if (code != null) {
      _villages = await ApiService.fetchVillages('$code');
      setState(() {});
    }
  }

  Future<void> _onVillageChanged(int? code) async {
    setState(() => _villCode = code);
    if (code != null) {
      final zip = await ApiService.fetchPostalCodeByVillage('$code');
      if (zip != null && zip.isNotEmpty) {
        setState(() => _zipCtrl.text = zip);
      }
    }
  }

  Future<void> _pickFromGallery() async {
    try {
      final files = await _picker.pickMultiImage(imageQuality: 85);
      if (!mounted) return;
      if (files.isNotEmpty) setState(() => _photos.addAll(files));
    } catch (_) {}
  }

  Future<void> _pickFromCamera() async {
    try {
      final file =
          await _picker.pickImage(source: ImageSource.camera, imageQuality: 85);
      if (!mounted) return;
      if (file != null) setState(() => _photos.add(file));
    } catch (_) {}
  }

  void _removePhoto(int index) => setState(() => _photos.removeAt(index));

  @override
  void dispose() {
    _pilihanCtrl.dispose();
    _dataBaruCtrl.dispose();
    _zipCtrl.dispose();
    _detailAddrCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    if (_deptId == null || _empId == null || _custId == null || _catId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Lengkapi Department, Karyawan, Customer, Kategori')),
      );
      return;
    }

    setState(() => _submitting = true);
    try {
      final ok = await ApiService.createPerbaikanData(
        departmentId: _deptId!,
        employeeId: _empId!,
        customerId: _custId!,
        customerCategoryId: _catId!,
        pilihanData: _pilihanCtrl.text.trim(),
        dataBaru: _dataBaruCtrl.text.trim().isEmpty ? null : _dataBaruCtrl.text.trim(),
        provinsiCode: _provCode?.toString(),
        kotaKabCode: _cityCode?.toString(),
        kecamatanCode: _distCode?.toString(),
        kelurahanCode: _villCode?.toString(),
        kodePos: _zipCtrl.text.trim().isEmpty ? null : _zipCtrl.text.trim(),
        detailAlamat: _detailAddrCtrl.text.trim().isEmpty ? null : _detailAddrCtrl.text.trim(),
        // nama wilayah (opsional, kalau mau isi — bisa diambil dari OptionItem name)
        provinsiName: _provinces.firstWhere((e) => e.id == _provCode, orElse: () => OptionItem(id: -1, name: '')).name,
        kotaKabName: _cities.firstWhere((e) => e.id == _cityCode, orElse: () => OptionItem(id: -1, name: '')).name,
        kecamatanName: _districts.firstWhere((e) => e.id == _distCode, orElse: () => OptionItem(id: -1, name: '')).name,
        kelurahanName: _villages.firstWhere((e) => e.id == _villCode, orElse: () => OptionItem(id: -1, name: '')).name,
        photos: _photos,
      );

      if (!mounted) return;
      if (ok) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Perbaikan data tersimpan'), backgroundColor: Colors.green),
        );
        Navigator.pop(context, true);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Gagal menyimpan'), backgroundColor: Colors.red),
        );
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error: $e'), backgroundColor: Colors.red),
      );
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isWide = MediaQuery.of(context).size.width >= 900;

    return Scaffold(
      backgroundColor: const Color(0xFF0A1B2D),
      appBar: AppBar(
        backgroundColor: Colors.white,
        foregroundColor: Colors.black,
        elevation: 1,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: Colors.black),
          onPressed: () => Navigator.pop(context),
        ),
        title: const Text('nanopiko', style: TextStyle(color: Colors.black)),
        actions: [
          TextButton.icon(
            onPressed: _submitting ? null : _save,
            icon: const Icon(Icons.save, color: Colors.blue),
            label: const Text('Simpan', style: TextStyle(color: Colors.blue)),
          )
        ],
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Form(
            key: _formKey,
            child: AbsorbPointer(
              absorbing: _loadingOptions || _submitting,
              child: Opacity(
                opacity: (_loadingOptions || _submitting) ? .6 : 1,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Create Perbaikan Data',
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: isWide ? 20 : 18,
                          fontWeight: FontWeight.bold,
                        )),
                    const SizedBox(height: 20),

                    // ===== FORM UTAMA =====
                    Wrap(
                      spacing: 20,
                      runSpacing: 16,
                      children: [
                        _wrapField(
                          isWide,
                          _dropdownInt(
                            label: 'Department *',
                            value: _deptId,
                            items: _departments,
                            onChanged: _onDeptChanged,
                          ),
                        ),
                        _wrapField(
                          isWide,
                          _dropdownInt(
                            label: 'Karyawan *',
                            value: _empId,
                            items: _employees,
                            onChanged: _onEmpChanged,
                          ),
                        ),
                        _wrapField(
                          isWide,
                          _dropdownInt(
                            label: 'Kategori Customer *',
                            value: _catId,
                            items: _categories,
                            onChanged: _onCatChanged,
                          ),
                        ),
                        _wrapField(
                          isWide,
                          _dropdownInt(
                            label: 'Customer *',
                            value: _custId,
                            items: _customers,
                            onChanged: (v) => setState(() => _custId = v),
                          ),
                        ),
                        _wrapField(
                          isWide,
                          _textField(
                            controller: _pilihanCtrl,
                            label: 'Pilihan Data * (Contoh: Telepon)',
                            validator: (v) => (v == null || v.trim().isEmpty)
                                ? 'Wajib diisi'
                                : null,
                          ),
                        ),
                        _wrapField(
                          isWide,
                          _textField(
                            controller: _dataBaruCtrl,
                            label: 'Data Baru (Contoh: 089888899998)',
                            maxLines: 5,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 24),

                    // ===== BLOK ALAMAT (2 kolom) =====
                    _addressBlock(context),

                    const SizedBox(height: 24),

                    // ===== BLOK GAMBAR ===== (tetap)
                    // (kode gambar kamu yang sebelumnya — tetap sama)
                    // ... <<biarkan yang kamu punya, tidak diulang di sini>>
                    // --- aku biarkan konten gambar-mu apa adanya ---

                    const SizedBox(height: 30),

                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        onPressed: _submitting ? null : _save,
                        icon: const Icon(Icons.save),
                        label: const Text('Simpan'),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.blue,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 16),
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12)),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  // ====== widget helpers untuk dropdown Int (OptionItem) ======
  Widget _dropdownInt({
    required String label,
    required int? value,
    required List<OptionItem> items,
    required ValueChanged<int?> onChanged,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _label(label),
        const SizedBox(height: 6),
        DropdownButtonFormField<int>(
          value: value,
          isExpanded: true,
          items: items
              .map((o) => DropdownMenuItem<int>(
                    value: o.id,
                    child: Text(o.name),
                  ))
              .toList(),
          onChanged: onChanged,
          decoration: InputDecoration(
            filled: true,
            fillColor: const Color(0xFF22344C),
            contentPadding:
                const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: const BorderSide(color: Colors.white24),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: const BorderSide(color: Colors.white54),
            ),
          ),
          dropdownColor: const Color(0xFF22344C),
          iconEnabledColor: Colors.white,
          style: const TextStyle(color: Colors.white),
          validator: (v) =>
              (label.contains('*') && v == null) ? 'Wajib dipilih' : null,
        ),
      ],
    );
  }

  // ====== (Address block & textfield helpers) — pakai versi 2 kolom yang sudah kamu punya ======
  // (pakai _addressBlock, _darkDropdown, _darkTextField, _label, _wrapField dari file kamu — tidak diubah)
}
