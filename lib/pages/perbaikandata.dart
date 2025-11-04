// lib/pages/perbaikandata.dart
import 'package:flutter/material.dart';

import '../models/perbaikan_data.dart';
import '../services/api_service.dart';              // <— ADD
import 'create_perbaikandata.dart';                // pastikan path benar

class PerbaikanDataScreen extends StatefulWidget {
  const PerbaikanDataScreen({super.key});

  @override
  State<PerbaikanDataScreen> createState() => _PerbaikanDataScreenState();
}

class _PerbaikanDataScreenState extends State<PerbaikanDataScreen> {
  final TextEditingController _searchCtrl = TextEditingController();

  List<PerbaikanData> _all = [];
  bool _loading = false;
  String? _error;

  String get _q => _searchCtrl.text.trim().toLowerCase();

  List<PerbaikanData> get _filtered {
    if (_q.isEmpty) return _all;
    return _all.where((d) {
      final blob =
          '${d.departmentName ?? ''} ${d.employeeName ?? ''} ${d.customerName ?? ''} ${d.customerCategoryName ?? ''} ${d.pilihanData ?? ''} ${d.dataBaru ?? ''} ${d.alamatDisplay ?? ''}'
              .toLowerCase();
      return blob.contains(_q);
    }).toList();
  }

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  Future<void> _fetch() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final items = await ApiService.fetchPerbaikanData(
        perPage: 1000,
        q: _q.isEmpty ? null : _q,
      );
      setState(() => _all = items);
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  // ... (sisanya UI-mu tetap, hanya _buildCreateButton panggil CreatePerbaikanDataScreen)
}
